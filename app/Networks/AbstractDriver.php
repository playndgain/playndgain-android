<?php

namespace App\Networks;

use App\Enums\ConnectorCategory;
use App\Models\Network;
use App\Networks\Contracts\NetworkDriver;
use App\Networks\Support\OfferSkips;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class AbstractDriver implements NetworkDriver
{
    /**
     * Built on first use rather than in the constructor: most drivers never
     * skip anything, and a driver is constructed on every postback.
     */
    private ?OfferSkips $skips = null;

    public function __construct(protected Network $network) {}

    public function network(): Network
    {
        return $this->network;
    }

    /**
     * A direct partner with a headline-cased key, which is right for most of
     * them and wrong for the ones whose brand has its own capitalisation.
     * Those override; `Str::headline()` is the floor, not the intent.
     */
    public static function connector(): Connector
    {
        return new Connector(Str::headline(static::key()), ConnectorCategory::Direct);
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
     * The `#` in a Swaarm macro is part of the token and never a fragment,
     * which is also why the query is appended after `url()` rather than passed
     * through it.
     */
    public static function postbackPath(string $postbackKey): string
    {
        $base = url('/postback/'.$postbackKey);
        $pairs = [];

        foreach (static::postbackQuery() as $key => $macro) {
            $pairs[] = $key.'='.$macro;
        }

        return $pairs === [] ? $base : $base.'?'.implode('&', $pairs);
    }

    public function postbackUrl(): string
    {
        return static::postbackPath((string) $this->network->postback_key);
    }

    /**
     * @return array<string, array{count: int, samples: list<string>}>
     */
    public function skippedOffers(): array
    {
        return $this->skips()->all();
    }

    protected function skips(): OfferSkips
    {
        return $this->skips ??= new OfferSkips;
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
     *
     * Redirects are not followed. Every driver here talks to a fixed host and
     * none of them relies on a 3xx today, but two of the hosts are becoming
     * admin-supplied (a platform tenant's own domain), and a redirect is the
     * cheapest way to walk a validated public host to a private one. Refusing
     * the hop keeps the URL we validated the URL we called.
     */
    protected function http(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(3, 200, throw: false)
            ->withoutRedirecting()
            ->withUserAgent('PLAYNDGAIN/1.0');
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
