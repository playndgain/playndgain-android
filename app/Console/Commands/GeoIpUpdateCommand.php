<?php

namespace App\Console\Commands;

use App\Fraud\MaxMindIpIntelligence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The monthly refresh docs/05-fraud-engine.md §4 assumes, run weekly.
 *
 * GeoLite2 is republished twice a week and IP2Proxy LITE monthly, so a weekly
 * pull is never more than a few days stale and stays well inside IP2Location's
 * five-downloads-a-day free-tier cap.
 *
 * Every source is independent: a missing credential is a warning, a failed
 * download fails only its own database, and neither stops the others. The
 * reader degrades to `IpVerdict::unknown()` for whatever is absent, so a
 * partial refresh is a partial outage rather than a total one.
 */
class GeoIpUpdateCommand extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Download the GeoLite2 and IP2Proxy LITE databases the fraud engine reads.';

    /**
     * PX11 carries the proxy type the `vpn_proxy_ip` rule reads. There is no
     * separate IPv6 edition to ask for — unlike IP2Location's geolocation LITE,
     * an IP2Proxy BIN indexes both address families in the one file, and the
     * `…IPV6` suffix that works there 404s here.
     */
    private const IP2LOCATION_FILE = 'PX11LITEBIN';

    /** Several hundred megabytes over a free-tier download server. */
    private const TIMEOUT = 1800;

    public function handle(): int
    {
        $directory = MaxMindIpIntelligence::directory();

        // Staging sits inside the target directory so the final rename() is a
        // same-filesystem move. Across devices rename() degrades to a copy and
        // a reader can observe a half-written database.
        $staging = $directory.DIRECTORY_SEPARATOR.'.staging';

        File::ensureDirectoryExists($staging);

        $failed = false;

        foreach ($this->sources() as [$label, $database, $url, $archive]) {
            if ($url === null) {
                $this->warn("{$label}: skipped — no credential configured.");

                continue;
            }

            try {
                $this->refresh($label, $url, $staging.DIRECTORY_SEPARATOR.$archive, MaxMindIpIntelligence::file($database));
            } catch (Throwable $e) {
                $this->error("{$label}: {$e->getMessage()}");
                $failed = true;
            }
        }

        File::deleteDirectory($staging);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Label, database filename, download URL (null when unconfigured), staging name.
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: string}>
     */
    private function sources(): array
    {
        $key = config('advault.geoip_maxmind_license_key');
        $token = config('advault.geoip_ip2location_token');

        $maxmind = fn (string $edition): ?string => is_string($key) && $key !== ''
            ? 'https://download.maxmind.com/app/geoip_download?'.http_build_query([
                'edition_id' => $edition,
                'license_key' => $key,
                'suffix' => 'tar.gz',
            ])
            : null;

        return [
            ['GeoLite2-Country', MaxMindIpIntelligence::COUNTRY_DB, $maxmind('GeoLite2-Country'), 'country.tar.gz'],
            ['GeoLite2-ASN', MaxMindIpIntelligence::ASN_DB, $maxmind('GeoLite2-ASN'), 'asn.tar.gz'],
            ['IP2Proxy LITE', MaxMindIpIntelligence::PROXY_DB, is_string($token) && $token !== ''
                ? 'https://www.ip2location.com/download/?'.http_build_query(['token' => $token, 'file' => self::IP2LOCATION_FILE])
                : null, 'ip2proxy.zip'],
        ];
    }

    private function refresh(string $label, string $url, string $archive, string $target): void
    {
        $this->line("{$label}: downloading…");

        $this->download($url, $archive);
        self::install($archive, $target);

        $this->info("{$label}: installed, ".number_format((int) filesize($target) / 1048576, 1).' MB.');
    }

    private function download(string $url, string $to): void
    {
        $response = Http::timeout(self::TIMEOUT)->sink($to)->get($url);

        if (! $response->successful()) {
            // MaxMind answers an invalid or unentitled licence key with 401.
            throw new RuntimeException("the download returned HTTP {$response->status()}.");
        }

        // IP2Location answers a bad or exhausted token with HTTP 200 and a
        // plain-text body — "NO PERMISSION", "TOO MANY DOWNLOAD REQUEST". The
        // magic bytes are the only reliable tell that an archive arrived.
        $magic = (string) file_get_contents($to, length: 2);

        if ($magic !== 'PK' && $magic !== "\x1f\x8b") {
            throw new RuntimeException('the server answered "'.trim((string) file_get_contents($to, length: 120)).'".');
        }
    }

    /**
     * Extract the one database inside `$archive` and put it at `$target`.
     *
     * Public and static because this is the half worth testing: the archive
     * layouts are not ours to rely on. MaxMind nests the .mmdb under a dated
     * directory whose name changes every release, and IP2Location names the BIN
     * inside the zip after the edition rather than after the file the reader
     * opens. Both are found by extension, not by expected name.
     */
    public static function install(string $archive, string $target): void
    {
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $part = $target.'.part';

        try {
            $found = str_ends_with(strtolower($archive), '.zip')
                ? self::fromZip($archive, $extension, $part)
                : self::fromTar($archive, $extension, $part);

            if (! $found) {
                throw new RuntimeException("the archive contains no .{$extension} file.");
            }

            self::verify($part, basename($target));

            // ponytail: a plain rename. Same directory, so it is atomic on
            // Linux, which is where this runs; on Windows it can lose to a
            // reader still holding the old file open.
            if (! rename($part, $target)) {
                throw new RuntimeException('the database could not be moved into place.');
            }
        } catch (Throwable $e) {
            // Whatever went wrong, the previous database is still the one on
            // disk — a stale country lookup beats no country lookup.
            @unlink($part);

            throw $e;
        }
    }

    private static function fromTar(string $archive, string $extension, string $to): bool
    {
        foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
            if (strtolower($file->getExtension()) === $extension) {
                // A phar:// path, which copy() reads like any other stream.
                return copy($file->getPathname(), $to);
            }
        }

        return false;
    }

    private static function fromZip(string $archive, string $extension, string $to): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new RuntimeException('the downloaded zip could not be opened.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension) {
                    continue;
                }

                $from = $zip->getStream($name);
                $into = fopen($to, 'wb');

                if ($from === false || $into === false) {
                    throw new RuntimeException("{$name} could not be read out of the zip.");
                }

                try {
                    stream_copy_to_stream($from, $into);
                } finally {
                    fclose($from);
                    fclose($into);
                }

                return true;
            }
        } finally {
            $zip->close();
        }

        return false;
    }

    /**
     * Open the extracted file with the reader that will serve it in production.
     * This is what catches a truncated download before it replaces a good file.
     */
    private static function verify(string $path, string $database): void
    {
        $class = MaxMindIpIntelligence::READERS[$database];

        if (! class_exists($class)) {
            return;
        }

        $reader = null;

        try {
            $reader = new $class($path);

            // Read something. Both constructors parse the header, but asking
            // for a version is the cheap proof that the body is there too.
            $database === MaxMindIpIntelligence::PROXY_DB
                ? $reader->getDatabaseVersion()
                : $reader->metadata();
        } catch (Throwable $e) {
            throw new RuntimeException('the downloaded database did not open — '.$e->getMessage());
        } finally {
            // Released before the rename, so the file is not held open.
            $reader?->close();
        }
    }
}
