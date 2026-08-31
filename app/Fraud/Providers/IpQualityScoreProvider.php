<?php

namespace App\Fraud\Providers;

use App\Enums\ProxyType;
use App\Fraud\IpVerdict;
use App\Models\IpProvider;

/**
 * ipqualityscore.com. Paid, and the only source here that returns a calibrated
 * fraud score rather than a classification we score ourselves.
 *
 * It answers several overlapping booleans rather than one class, and they are
 * not mutually exclusive — a Tor exit is also a proxy. Read most specific
 * first, or every Tor node classifies as a plain proxy.
 */
class IpQualityScoreProvider extends AbstractIpProvider
{
    public static function key(): string
    {
        return 'ipqualityscore';
    }

    protected function request(string $ip, IpProvider $config): array
    {
        // Key and address go in the query string, not the path. Their docs
        // offer this form so a key containing `/` or `+` is not split into
        // extra path segments — which they reject as "Invalid or unauthorized
        // key" rather than as a malformed URL. `trim` is the copy-paste
        // counterpart of the same failure.
        return ['https://ipqualityscore.com/api/json/ip', [
            'key' => trim((string) $config->api_key),
            'ip' => $ip,
            'strictness' => 1,
            // Their `mobile` flag does not mean "be mobile-aware", it means
            // "score this address as if the device were mobile" — which is
            // right for a wall served inside a phone app and, in their own
            // warning, "can cause unexpected and abnormal results if the
            // device is not a mobile device". It is the substitute for the
            // `user_agent` they would rather have; passing the real one would
            // let them detect the device themselves, but the driver contract
            // only carries the address.
            'mobile' => 'true',
        ]];
    }

    /** `success: false` carries their own reason in `message` — worth more to an operator than "unreadable". */
    protected function rejectionReason(array $body): ?string
    {
        if (($body['success'] ?? true) === true) {
            return null;
        }

        return is_string($body['message'] ?? null)
            ? "ipqualityscore: {$body['message']}"
            : 'ipqualityscore: rejected';
    }

    /** Their sentinel for a field they could not fill is the string `N/A`. */
    private static function known(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strcasecmp($value, 'N/A') !== 0 ? $value : null;
    }

    protected function parse(string $ip, array $body): ?IpVerdict
    {
        if (($body['success'] ?? false) !== true) {
            return null;
        }

        $type = match (true) {
            ($body['tor'] ?? false) === true => ProxyType::Tor,
            ($body['vpn'] ?? false) === true => ProxyType::Vpn,
            ($body['proxy'] ?? false) === true => ProxyType::Public,
            default => null,
        };

        return new IpVerdict(
            // Their sentinel for an unknown field is the string `N/A`, not
            // null and not an absent key — so the naive read stored a country
            // of "N/A" and an organization of "N/A" as if the vendor had
            // answered. A country every country rule then compares against is
            // the expensive half of that: `N/A` matches no list, so an
            // address whose country they could not place quietly passed every
            // geo check instead of being treated as unplaced.
            country: self::iso(self::known($body['country_code'] ?? null)),
            // Their docs say an unknown ASN comes back null, but `0` is not a
            // real one either — it is reserved, never routed — and a stored
            // `0` is worse than nothing: `IpVerdict::merge()` keeps the first
            // non-null, so it would outrank the real ASN a second provider
            // still had.
            asn: is_numeric($body['ASN'] ?? null) && (int) $body['ASN'] > 0 ? (int) $body['ASN'] : null,
            organization: self::known($body['ISP'] ?? null),
            // Their own words for it, and an exact string from a closed set
            // — `Residential`, `Corporate`, `Education`, `Mobile`,
            // `Data Center`. `host` and `organization` describe the owner,
            // not the use.
            isDatacenter: ($body['connection_type'] ?? null) === 'Data Center',
            isProxy: ($body['proxy'] ?? false) === true,
            proxyType: $type,
            riskScore: self::riskScore($body['fraud_score'] ?? null),
            source: self::key(),
        );
    }
}
