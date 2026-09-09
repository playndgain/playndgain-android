<?php

namespace App\Fraud;

use App\Fraud\Contracts\IpIntelligence;

/**
 * A geo stand-in for local development, and nothing else.
 *
 * The wall fails closed on an address it cannot place (docs/03 §2): no country,
 * no offers, "we could not confirm your device". On a developer's machine every
 * request arrives from `127.0.0.1`, which no GeoIP database will ever place, so
 * the wall is permanently empty and unpreviewable. The documented escape —
 * `?country=XX` under `apps.test_mode` — needs a per-app toggle and has to be
 * typed onto every URL, which a nested iframe, a panel preview and a phone on
 * the same wifi cannot do.
 *
 * So: private and reserved addresses get a configured country, everything
 * routable is delegated to the real reader untouched. It decorates
 * `IpIntelligence` rather than special-casing the wall, so the fraud rules and
 * `ConversionProcessor` see the same country the wall did — a click fired in
 * dev looks like a real one instead of a `geo_mismatch`.
 *
 * **It is bound only when `app()->environment('local')` and
 * `playndgain.geoip_dev_country` is set** — see AppServiceProvider. Unset config is
 * today's behaviour exactly, and no other environment can reach the binding. A
 * decorator that places an address the databases refused is precisely what must
 * never run in production: every eligibility decision downstream trusts the
 * country, and inventing one for a real user is the chargeback the fail-closed
 * rule exists to avoid.
 */
class LocalIpIntelligence implements IpIntelligence
{
    public function __construct(
        private readonly IpIntelligence $inner,
        private readonly string $country,
    ) {}

    public function inspect(string $ip, bool $consented = false): IpVerdict
    {
        if (! self::isLocal($ip)) {
            return $this->inner->inspect($ip, $consented);
        }

        // Uncached on purpose. The verdict is a constant, the branch is two
        // function calls, and writing it under the shared `ip:local:v2:` key
        // would leave a fabricated country in Redis for an hour after the
        // config is removed.
        return new IpVerdict(country: strtoupper($this->country));
    }

    /** Loopback, the private ranges, and everything else not routable. */
    private static function isLocal(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
