<?php

namespace App\Fraud\Providers;

use App\Enums\ProxyType;
use App\Fraud\IpVerdict;
use App\Models\IpProvider;

/**
 * proxycheck.io. Free tier is 1000 lookups a day with no card, which is why it
 * seeds first — it is the provider an operator can switch on today.
 *
 * The response is keyed by the address queried, not by a fixed field name, and
 * `vpn=1&asn=1&risk=1` are opt-in flags rather than defaults.
 */
class ProxyCheckProvider extends AbstractIpProvider
{
    public static function key(): string
    {
        return 'proxycheck';
    }

    protected function request(string $ip, IpProvider $config): array
    {
        return ["https://proxycheck.io/v2/{$ip}", [
            'key' => $config->api_key,
            // Classify the tunnel type rather than yes/no. Note their own
            // billing rule: an address detected as both a proxy and a VPN
            // spends two queries of the daily allowance on `vpn=3`, so the
            // vendor-side allowance can run out ahead of this row's
            // `daily_cap`, which counts requests. That shows up as a `denied`
            // status, which the breaker already sees.
            'vpn' => 3,
            'asn' => 1,
            'risk' => 1,
        ]];
    }

    protected function parse(string $ip, array $body): ?IpVerdict
    {
        // `status` is `ok`, `warning`, `denied` or `error`. Only the first two
        // carry a record; the others are a key or quota problem, which is a
        // failure the breaker should see rather than a clean verdict.
        if (! in_array($body['status'] ?? null, ['ok', 'warning'], true)) {
            return null;
        }

        $record = $body[$ip] ?? null;

        if (! is_array($record)) {
            return null;
        }

        $proxy = ($record['proxy'] ?? 'no') === 'yes';
        $rawType = $record['type'] ?? null;
        $type = ProxyType::parse(self::normaliseType($rawType));

        return new IpVerdict(
            country: self::iso($record['isocode'] ?? null),
            asn: self::asn($record['asn'] ?? null),
            organization: is_string($record['provider'] ?? null) ? $record['provider'] : null,
            isDatacenter: $type === ProxyType::Datacenter,
            isProxy: $proxy,
            proxyType: $type,
            threat: self::threat($rawType),
            riskScore: self::riskScore($record['risk'] ?? null),
            source: self::key(),
        );
    }

    /** `denied`/`error` are proxycheck's own word for a key or quota problem — worth more to an operator than "unreadable". */
    protected function rejectionReason(array $body): ?string
    {
        $status = $body['status'] ?? null;

        return is_string($status) && ! in_array($status, ['ok', 'warning'], true)
            ? "proxycheck: {$status}"
            : null;
    }

    /**
     * proxycheck writes the class in prose; the enum wants IP2Proxy's codes.
     * The arms below are their documented v2 `type` list, not a guess at it —
     * the earlier `Public Proxy` and `Web Proxy` arms matched no value the
     * vendor ever sends, while half the open-proxy protocols they do send
     * were missing, which left a detected SOCKS5H or Shadowsocks exit with no
     * class at all. That is not a harmless gap: `VpnProxyIpRule` blocks the
     * click either way, but only an explicit consumer class refuses a
     * *render*, so an unmapped open proxy silently bought a rendered wall.
     *
     * `OpenVPN` maps to `PUB` rather than `VPN` on the vendor's own reading —
     * they send it for open/public OpenVPN servers "used like SOCKS5
     * proxies", and a paid private VPN comes back as plain `VPN`.
     *
     * Four documented types stay deliberately unmapped, and each one is a
     * decision rather than an omission:
     *
     * - `Business` is a corporate/office ISP allocation, not cloud
     *   infrastructure — mapping it to `DCH` would refuse the wall to a real
     *   user on a work connection.
     * - `Wireless` is a carrier/mobile allocation, which is most of this
     *   platform's real traffic.
     * - `Scraper` is a bot classification, not an infrastructure class.
     * - `Inference Engine` is their own "a proxy of a type we could not
     *   determine", which is exactly the unclassified fallback
     *   `VpnProxyIpRule` already handles; naming a class here would claim
     *   more than the vendor did.
     *
     * `Compromised Server` is read separately as a threat signal below.
     * Anything else — a class they add later, a `whitelisted by`/`blacklisted
     * by` account rule — still fails open: an unmapped type leaves the
     * address judged on every other signal, which is the safe direction.
     */
    private static function normaliseType(mixed $type): ?string
    {
        return match (is_string($type) ? strtoupper($type) : null) {
            'VPN' => 'VPN',
            'TOR' => 'TOR',
            'HOSTING' => 'DCH',
            'RESIDENTIAL' => 'RES',
            'SOCKS', 'SOCKS4', 'SOCKS4A', 'SOCKS5', 'SOCKS5H', 'SHADOWSOCKS', 'HTTP', 'HTTPS', 'OPENVPN' => 'PUB',
            default => null,
        };
    }

    /**
     * `Compromised Server` marks port-scan evidence of exposed or vulnerable
     * services on the device — a fact about what happened to the host, not
     * about who owns it. `IpVerdict::$threat` exists for exactly this kind of
     * signal; no rule reads it yet, so this only preserves the datum rather
     * than inventing a blocking behaviour nobody asked for.
     */
    private static function threat(mixed $type): ?string
    {
        return is_string($type) && strtoupper($type) === 'COMPROMISED SERVER'
            ? 'COMPROMISED_SERVER'
            : null;
    }

    /** `AS24940` — the prefix is theirs, the number is ours. */
    private static function asn(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = ltrim($value, 'ASas');

        return ctype_digit($digits) ? (int) $digits : null;
    }
}
