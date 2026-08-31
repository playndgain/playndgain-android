<?php

namespace App\Fraud;

use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use IP2Proxy\Database as IP2ProxyDatabase;
use Throwable;

/**
 * Local databases, never a per-click API call — docs/05 §4.
 *
 * The GeoLite2 and IP2Proxy files are downloaded weekly by `geoip:update`, not
 * vendored, and the reader packages are optional. Every lookup degrades to
 * `IpVerdict::unknown()` instead of throwing: an offerwall that stops serving
 * because a monthly database refresh failed is a far worse outage than an hour
 * of unplaced IPs, and `NullIpIntelligence` documents what unknown costs.
 *
 * Drop-in replacement for the null binding — same contract, same cache key,
 * same one-hour TTL, so swapping the binding changes no call site.
 */
class MaxMindIpIntelligence implements IpIntelligence
{
    /** Mobile carrier IPs rotate. A day of caching would misattribute them. */
    public const TTL = 3600;

    /** Where the scheduled database refresh drops the files. */
    public const DEFAULT_PATH = 'app/geoip';

    public const COUNTRY_DB = 'GeoLite2-Country.mmdb';

    public const ASN_DB = 'GeoLite2-ASN.mmdb';

    public const PROXY_DB = 'IP2PROXY-LITE-PX11.BIN';

    /**
     * Label => filename. `geoip:update` writes these and the admin Health page
     * reports on them, so the names live here rather than in three places.
     *
     * @var array<string, string>
     */
    public const DATABASES = [
        'Country' => self::COUNTRY_DB,
        'ASN' => self::ASN_DB,
        'Proxy' => self::PROXY_DB,
    ];

    /** Reader class per database — the update command verifies with these. */
    public const READERS = [
        self::COUNTRY_DB => 'GeoIp2\\Database\\Reader',
        self::ASN_DB => 'GeoIp2\\Database\\Reader',
        self::PROXY_DB => 'IP2Proxy\\Database',
    ];

    /**
     * Curated hosting ASNs — the "datacenter" half of docs/05 §4.
     *
     * GeoLite2-ASN gives the number; deciding which numbers are hosting is a
     * judgement call, so it lives here where it can be read and argued with.
     * Cloudflare (13335) is deliberately absent: WARP is consumer traffic and
     * this list feeds a `block` rule.
     *
     * @var list<int>
     */
    public const HOSTING_ASNS = [
        16509, 14618, 7224,          // Amazon AWS
        15169, 396982, 19527,        // Google / GCP
        8075, 8068, 8069,            // Microsoft / Azure
        16276,                       // OVH
        24940, 213230,               // Hetzner
        14061,                       // DigitalOcean
        20473,                       // Vultr / Choopa
        63949,                       // Linode / Akamai
        51167,                       // Contabo
        9009,                        // M247
        60068,                       // Datacamp
        45102, 37963,                // Alibaba
        132203, 45090,               // Tencent
        12876,                       // Scaleway
        60781, 28753,                // Leaseweb
        31898,                       // Oracle Cloud
        26496,                       // GoDaddy
        47583,                       // Hostinger
    ];

    /**
     * Opened readers, keyed by file. Null means "tried and unavailable" — the
     * absence is cached too, so a missing file is not a `is_file()` syscall on
     * every click.
     *
     * @var array<string, mixed>
     */
    private array $readers = [];

    public function inspect(string $ip, bool $consented = false): IpVerdict
    {
        return IpVerdict::remember("ip:local:v2:{$ip}", self::TTL, fn (): IpVerdict => $this->lookup($ip));
    }

    private function lookup(string $ip): IpVerdict
    {
        [$asn, $organization] = $this->asn($ip);
        $proxy = $this->proxy($ip);

        return new IpVerdict(
            country: $this->country($ip),
            asn: $asn,
            organization: $organization,
            isDatacenter: $asn !== null && in_array($asn, self::HOSTING_ASNS, true),
            isProxy: $proxy['is_proxy'],
            proxyType: $proxy['proxy_type'],
            usageType: $proxy['usage_type'],
            threat: $proxy['threat'],
        );
    }

    private function country(string $ip): ?string
    {
        $reader = $this->reader(self::COUNTRY_DB);

        if ($reader === null) {
            return null;
        }

        try {
            $iso = $reader->country($ip)->country->isoCode;
        } catch (Throwable) {
            // A private range, an unallocated block, a malformed address. The
            // database cannot place it — that is unknown, not an error.
            return null;
        }

        return is_string($iso) && $iso !== '' ? strtoupper($iso) : null;
    }

    /** @return array{0: ?int, 1: ?string} */
    private function asn(string $ip): array
    {
        $reader = $this->reader(self::ASN_DB);

        if ($reader === null) {
            return [null, null];
        }

        try {
            $record = $reader->asn($ip);
            $number = $record->autonomousSystemNumber;
            $organization = $record->autonomousSystemOrganization;
        } catch (Throwable) {
            return [null, null];
        }

        return [
            is_numeric($number) ? (int) $number : null,
            is_string($organization) ? $organization : null,
        ];
    }

    /**
     * One `lookup(ALL)` rather than four single-field calls — the reader walks
     * the same index either way, and four walks for one row is three too many
     * on the click path.
     *
     * `FRAUD_SCORE` is deliberately not read. The LITE file does not carry it
     * and returns the vendor's "please upgrade this data file" sentence in its
     * place, which is a string, not a score. The third-party providers fill it.
     *
     * @return array{is_proxy: bool, proxy_type: ?ProxyType, usage_type: ?string, threat: ?string}
     */
    private function proxy(string $ip): array
    {
        $none = ['is_proxy' => false, 'proxy_type' => null, 'usage_type' => null, 'threat' => null];

        $reader = $this->reader(self::PROXY_DB);

        if ($reader === null) {
            return $none;
        }

        try {
            $record = $reader->lookup($ip, IP2ProxyDatabase::ALL);
        } catch (Throwable) {
            return $none;
        }

        if (! is_array($record)) {
            return $none;
        }

        return [
            // 0 = not a proxy, -1 = lookup failed, 1 = anonymiser, 2 = datacenter
            // or crawler. Failing closed would flag every address the moment the
            // file went stale, so anything not positive is "no".
            'is_proxy' => (int) ($record['isProxy'] ?? 0) > 0,
            'proxy_type' => ProxyType::parse(self::field($record, 'proxyType')),
            'usage_type' => self::field($record, 'usageType'),
            'threat' => self::field($record, 'threat'),
        ];
    }

    /**
     * IP2Proxy writes `-` for an unlisted address and a whole sentence for a
     * field this tier does not carry. Both mean "no value", and both would be
     * stored as data by a naive read.
     *
     * @param  array<string, mixed>  $record
     */
    private static function field(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        if (! is_string($value) || $value === '' || $value === '-' || str_contains($value, 'unavailable in selected')) {
            return null;
        }

        return $value;
    }

    private function reader(string $file): mixed
    {
        if (array_key_exists($file, $this->readers)) {
            return $this->readers[$file];
        }

        return $this->readers[$file] = $this->open($file);
    }

    private function open(string $file): mixed
    {
        $class = self::READERS[$file];
        $path = self::file($file);

        // The reader packages are not a hard dependency and the databases are
        // not in the repository. Both absences mean the same thing: we place
        // nobody until an operator provisions them.
        if (! class_exists($class) || ! is_file($path)) {
            return null;
        }

        try {
            return new $class($path);
        } catch (Throwable) {
            // A truncated download, a half-written file mid-refresh.
            return null;
        }
    }

    /** Where `geoip:update` writes and every reader looks. */
    public static function directory(): string
    {
        $dir = config('advault.geoip_path');

        return rtrim(is_string($dir) && $dir !== '' ? $dir : storage_path(self::DEFAULT_PATH), '/\\');
    }

    public static function file(string $name): string
    {
        return self::directory().DIRECTORY_SEPARATOR.$name;
    }
}
