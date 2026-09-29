<?php

/**
 * Regenerates app/Networks/Support/CountryProbeIps.php.
 *
 *   php scripts/generate-country-probe-ips.php <dir-with-rir-files> <country.mmdb>
 *
 * The directory holds the five RIR "delegated-*-extended-latest" files. For
 * each country the largest allocated or assigned IPv4 block (registered since
 * 2000 when there is one) is taken, and an address well inside it (the
 * midpoint plus 77, so never a network or broadcast address) is checked
 * against the country database: GeoLite2-Country, or DB-IP's country lite
 * file, which has the same shape. A mismatch
 * falls through to the next-largest block; a country with no match in its
 * eight largest blocks is left out rather than guessed.
 */

use MaxMind\Db\Reader;

require __DIR__.'/../vendor/autoload.php';

[$script, $dir, $mmdb] = $argv + [null, null, null];

if (! is_dir((string) $dir) || ! is_file((string) $mmdb)) {
    fwrite(STDERR, "usage: php {$script} <rir-dir> <country.mmdb>\n");
    exit(1);
}

$blocks = [];

foreach (glob(rtrim($dir, '/').'/delegated-*-extended-latest') ?: [] as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = explode('|', $line);

        if (count($f) < 7 || $f[2] !== 'ipv4' || ! in_array($f[6], ['allocated', 'assigned'], true)) {
            continue;
        }

        $cc = strtoupper($f[1]);

        if (preg_match('/^[A-Z]{2}$/', $cc) !== 1 || in_array($cc, ['EU', 'AP', 'ZZ'], true)) {
            continue;
        }

        $blocks[$cc][] = [ip2long($f[3]), (int) $f[4], (int) $f[5] >= 20000101];
    }
}

$reader = new Reader($mmdb);
$picked = [];
$dropped = [];

ksort($blocks);

foreach ($blocks as $cc => $list) {
    // Post-2000 blocks first: the largest legacy blocks are military and
    // corporate /8s (DoD, a carmaker) that geolocate correctly but look
    // like nobody's home connection. Size decides within each group.
    usort($list, fn (array $a, array $b): int => [$b[2], $b[1]] <=> [$a[2], $a[1]]);

    foreach (array_slice($list, 0, 8) as [$start, $count, $recent]) {
        if ($count < 256) {
            continue;
        }

        $ip = long2ip($start + intdiv($count, 2) + 77);
        $record = $reader->get($ip);

        if (is_array($record) && strtoupper((string) ($record['country']['iso_code'] ?? '')) === $cc) {
            $picked[$cc] = $ip;

            continue 2;
        }
    }

    $dropped[] = $cc;
}

$reader->close();

$lines = array_map(fn (string $cc, string $ip): string => "        '{$cc}' => '{$ip}',", array_keys($picked), $picked);
$date = date('Y-m-d');
$source = basename((string) $mmdb);

$php = <<<PHP
<?php

namespace App\\Networks\\Support;

/**
 * One public IPv4 address per country, for server-side calls that are
 * geo-filtered by the caller's IP (the probe matrix in AyetStudiosDriver).
 *
 * Generated {$date} by scripts/generate-country-probe-ips.php from the five
 * RIR delegated-extended files: an address inside the country's largest
 * allocated or assigned block, kept only when the country database
 * ({$source}) agrees.
 * Countries whose blocks all geolocated elsewhere are absent, not guessed.
 * Regenerate rather than hand-edit.
 */
final class CountryProbeIps
{
    /** @var array<string, string> ISO-2 => IPv4 */
    public const IPS = [
%LINES%
    ];

    public static function for(string \$country): ?string
    {
        return self::IPS[strtoupper(\$country)] ?? null;
    }

    /** @return list<string> */
    public static function countries(): array
    {
        return array_keys(self::IPS);
    }
}

PHP;

$php = str_replace('%LINES%', implode("\n", $lines), $php);

file_put_contents(__DIR__.'/../app/Networks/Support/CountryProbeIps.php', $php);

fwrite(STDERR, count($picked).' countries written; dropped: '.($dropped === [] ? 'none' : implode(', ', $dropped))."\n");
