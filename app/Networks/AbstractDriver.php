<?php

namespace App\Networks;

use App\Models\Network;
use App\Networks\Contracts\NetworkDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class AbstractDriver implements NetworkDriver
{
    public function __construct(protected Network $network) {}

    public function network(): Network
    {
        return $this->network;
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [];
    }

    /**
     * Absolute URL an admin copies into the network dashboard.
     *
     * Braces stay unencoded — `http_build_query()` would turn `{USER_ID}`
     * into `%7BUSER_ID%7D`, which most dashboards then send back literally.
     */
    public static function postbackUrl(): string
    {
        $base = url('/postback/'.static::key());
        $pairs = [];

        foreach (static::postbackQuery() as $key => $macro) {
            $pairs[] = $key.'='.$macro;
        }

        return $pairs === [] ? $base : $base.'?'.implode('&', $pairs);
    }

    /** Every driver that talks to an API has one; `direct` overrides. */
    public function syncsCatalogue(): bool
    {
        return true;
    }

    protected function credential(string $key): ?string
    {
        $value = data_get($this->network->credentials, $key);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * `throw: false` on the retry so a driver decides what a failed catalogue
     * pull means, rather than the HTTP client deciding for it.
     */
    protected function http(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(3, 200, throw: false)
            ->withUserAgent('AdVault/1.0');
    }

    /**
     * Normalize a network's country field to uppercase ISO-2.
     *
     * @param  string|array<int, string>|null  $countries
     * @return list<string>
     */
    protected function parseCountries(string|array|null $countries): array
    {
        if (blank($countries)) {
            return [];
        }

        $list = is_array($countries) ? $countries : (preg_split('/[,\s|]+/', $countries) ?: []);

        return collect($list)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn (string $code) => preg_match('/^[A-Z]{2}$/', $code) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Normalize a network's device field to our android|ios|desktop vocabulary.
     *
     * @param  string|array<int, string>|null  $devices
     * @return list<string>
     */
    protected function parseDevices(string|array|null $devices): array
    {
        if (blank($devices)) {
            return [];
        }

        $list = is_array($devices) ? $devices : (preg_split('/[,\s|]+/', $devices) ?: []);

        return collect($list)
            ->map(function ($device): ?string {
                $device = strtolower(trim((string) $device));

                return match (true) {
                    str_contains($device, 'android') => 'android',
                    str_contains($device, 'ios'), str_contains($device, 'iphone'), str_contains($device, 'ipad') => 'ios',
                    str_contains($device, 'desktop'), str_contains($device, 'web') => 'desktop',
                    default => null,
                };
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
