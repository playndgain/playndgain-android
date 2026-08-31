<?php

namespace App\Enums;

/**
 * The IP2Proxy `PROXY_TYPE` classes, which the paid providers also map onto.
 *
 * A closed set rather than the raw string, because three rules compare against
 * these values and a typo in one of them is a rule that silently never fires —
 * the failure mode a fraud signal can least afford.
 */
enum ProxyType: string
{
    case Vpn = 'VPN';
    case Tor = 'TOR';
    case Datacenter = 'DCH';
    case Public = 'PUB';
    case Web = 'WEB';
    case Search = 'SES';
    case Residential = 'RES';
    case Consumer = 'CPN';

    /**
     * IP2Proxy returns `-` for an unlisted address and a provider can return
     * anything at all, so an unrecognised value is "we do not know", never an
     * exception. A lookup that throws on a new class the vendor added would
     * take the wall down for a data refresh.
     */
    public static function parse(?string $value): ?self
    {
        if (! is_string($value) || $value === '' || $value === '-') {
            return null;
        }

        return self::tryFrom(strtoupper($value));
    }
}
