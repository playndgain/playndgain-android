<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One third-party IP intelligence provider: its configuration, and the live
 * state that decides whether the chain is allowed to call it right now.
 *
 * Three independent brakes, because a paid API on the click path can fail in
 * three unrelated ways — it can be slow (timeout), it can be down (breaker),
 * and it can be expensive (daily cap). Any one of them skipping the provider
 * leaves the local verdict standing, which is the behaviour the platform had
 * before providers existed.
 *
 * @property string $key
 * @property bool $enabled
 * @property ?string $api_key
 */
class IpProvider extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'fail_open' => 'boolean',
        'api_key' => 'encrypted',
        'circuit_opened_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    /**
     * A payload written under a previous APP_KEY is already unusable. Throwing
     * here 500s the Protection page and the wall chain for a secret nobody can
     * spend — treat it as missing so the operator can paste a replacement.
     */
    public function fromEncryptedString($value)
    {
        try {
            return parent::fromEncryptedString($value);
        } catch (DecryptException) {
            Log::warning('IpProvider encrypted attribute could not be decrypted', [
                'id' => $this->id,
                'key' => $this->key,
            ]);

            return null;
        }
    }

    /**
     * Every provider the chain may call, cheapest brake first.
     *
     * @return Collection<int, static>
     */
    public static function active(): Collection
    {
        return static::query()
            ->where('enabled', true)
            ->orderBy('priority')
            ->get()
            ->filter(fn (self $provider): bool => $provider->available())
            ->values();
    }

    public function available(): bool
    {
        return $this->enabled
            && filled($this->api_key)
            && ! $this->breakerOpen()
            && ! $this->capSpent();
    }

    /**
     * Open means "this provider was down a moment ago, do not open a socket".
     * It closes on time rather than on a probe: a half-open probe on the click
     * path is one user paying the timeout to find out for everyone else.
     */
    public function breakerOpen(): bool
    {
        return $this->circuit_opened_at !== null
            && $this->circuit_opened_at->addMinutes((int) $this->circuit_cooldown_minutes)->isFuture();
    }

    public function capSpent(): bool
    {
        return $this->daily_cap !== null && $this->spent() >= (int) $this->daily_cap;
    }

    public function spent(): int
    {
        return (int) Cache::get($this->counterKey(), 0);
    }

    public function recordSuccess(): void
    {
        $this->countRequest();

        if ($this->consecutive_failures === 0 && $this->circuit_opened_at === null) {
            return;
        }

        $this->forceFill([
            'consecutive_failures' => 0,
            'circuit_opened_at' => null,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();
    }

    /**
     * A failure still counts against the cap. It cost a socket and, with most
     * vendors, a request — a brake that only counts successes is a brake a
     * failing provider can drive straight through.
     */
    public function recordFailure(string $error): void
    {
        $this->countRequest();

        $failures = (int) $this->consecutive_failures + 1;

        $this->forceFill([
            'consecutive_failures' => $failures,
            'circuit_opened_at' => $failures >= (int) $this->circuit_failures ? now() : $this->circuit_opened_at,
            'last_error' => $error,
            'last_error_at' => now(),
        ])->save();
    }

    private function countRequest(): void
    {
        // Two days, not one: the key is dated, so an entry only has to outlive
        // its own day, and a TTL exactly one day long expires mid-day for a
        // counter written at 23:59.
        Cache::add($key = $this->counterKey(), 0, now()->addDays(2));
        Cache::increment($key);
    }

    public function counterKey(): string
    {
        return "ip_provider:{$this->key}:".now()->utc()->toDateString();
    }
}
