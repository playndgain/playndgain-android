<?php

namespace App\Fraud\Providers;

use App\Enums\ProxyType;
use App\Fraud\IpVerdict;
use App\Models\IpProvider;

/**
 * IPASIS IP intelligence. The IP-only endpoint classifies network privacy
 * signals but does not return the risk score offered by its email endpoint.
 */
class IpasisProvider extends AbstractIpProvider
{
    public static function key(): string
    {
        return 'ipasis';
    }

    protected function request(string $ip, IpProvider $config): array
    {
        return ['https://api.ipasis.com/v1/lookup', [
            'ip' => $ip,
            'key' => trim((string) $config->api_key),
        ]];
    }

    protected function parse(string $ip, array $body): ?IpVerdict
    {
        $privacy = $body['privacy'] ?? null;

        if (! is_array($privacy)) {
            return null;
        }

        $type = match (true) {
            ($privacy['tor'] ?? false) === true => ProxyType::Tor,
            ($privacy['vpn'] ?? false) === true => ProxyType::Vpn,
            ($privacy['proxy'] ?? false) === true => ProxyType::Public,
            default => null,
        };

        $asn = is_array($body['asn'] ?? null) ? $body['asn'] : [];
        $company = is_array($body['company'] ?? null) ? $body['company'] : [];

        return new IpVerdict(
            country: self::iso($body['country'] ?? null),
            asn: self::asn($asn['asn'] ?? null),
            organization: self::organization($company['name'] ?? null, $asn['name'] ?? null),
            isDatacenter: ($privacy['hosting'] ?? false) === true,
            isProxy: $type !== null || ($privacy['relay'] ?? false) === true,
            proxyType: $type,
            threat: ($privacy['abuse'] ?? false) === true ? 'ABUSE' : null,
            source: self::key(),
        );
    }

    /** IPASIS returns ASN values such as `AS15169`. */
    private static function asn(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = ltrim($value, 'ASas');

        return ctype_digit($digits) && (int) $digits > 0 ? (int) $digits : null;
    }

    private static function organization(mixed $company, mixed $asn): ?string
    {
        if (is_string($company) && $company !== '') {
            return $company;
        }

        return is_string($asn) && $asn !== '' ? $asn : null;
    }
}
