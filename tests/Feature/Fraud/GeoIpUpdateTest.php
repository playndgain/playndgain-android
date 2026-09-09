<?php

use App\Console\Commands\GeoIpUpdateCommand;
use App\Fraud\MaxMindIpIntelligence;
use Illuminate\Support\Facades\File;
use IP2Proxy\Database;

/*
|--------------------------------------------------------------------------
| geoip:update — the archive half
|--------------------------------------------------------------------------
|
| The download itself is one `Http::sink()` call and Http::fake() does not
| write to a sink, so faking it would prove nothing. What is worth pinning is
| what happens to the bytes afterwards: the member is found by extension
| inside an archive layout we do not control, and a bad download must never
| replace a good database.
|
*/

beforeEach(function () {
    $this->geoip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'geoip-test-'.getmypid();
    File::ensureDirectoryExists($this->geoip);
    config(['playndgain.geoip_path' => $this->geoip]);
});

afterEach(function () {
    File::deleteDirectory($this->geoip);
});

/** A zip whose single member is named after the edition, not after the target. */
function zipFixture(string $dir, string $member, string $body): string
{
    $path = $dir.DIRECTORY_SEPARATOR.'ip2proxy.zip';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($member, $body);
    $zip->close();

    return $path;
}

/**
 * A tar.gz whose .mmdb is buried under the dated directory MaxMind ships.
 *
 * The name is unique per call because Phar keeps a process-wide registry of
 * opened archives and refuses to re-create one it has already seen.
 */
function tarFixture(string $dir, string $member, string $body): string
{
    $path = $dir.DIRECTORY_SEPARATOR.uniqid('country-').'.tar';

    $tar = new PharData($path);
    $tar->addFromString($member, $body);
    $tar->compress(Phar::GZ);

    return $path.'.gz';
}

it('installs the database under the name the reader opens, whatever the archive called it', function () {
    $target = MaxMindIpIntelligence::file(MaxMindIpIntelligence::PROXY_DB);
    $sample = base_path('vendor/ip2location/ip2proxy-php/data/PX12.SAMPLE.BIN');

    $archive = zipFixture($this->geoip, 'IP2PROXY-LITE-PX11.IPV6.BIN', (string) file_get_contents($sample));

    GeoIpUpdateCommand::install($archive, $target);

    expect($target)->toBeFile()
        ->and(filesize($target))->toBe(filesize($sample))
        ->and($target.'.part')->not->toBeFile();

    // The proof that matters: the reader that serves production opens it.
    expect((new Database($target))->getDatabaseVersion())->not->toBeEmpty();
});

it('finds the mmdb inside the dated directory MaxMind nests it in', function () {
    $target = MaxMindIpIntelligence::file(MaxMindIpIntelligence::COUNTRY_DB);
    $archive = tarFixture($this->geoip, 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb', 'truncated');

    // The member is found — extraction gets far enough to hand a real file to
    // the reader, which is what then rejects it.
    expect(fn () => GeoIpUpdateCommand::install($archive, $target))
        ->toThrow(RuntimeException::class, 'did not open');
});

it('leaves the previous database in place when the download is corrupt', function () {
    $target = MaxMindIpIntelligence::file(MaxMindIpIntelligence::COUNTRY_DB);
    File::put($target, 'last good database');

    $archive = tarFixture($this->geoip, 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb', 'truncated');

    expect(fn () => GeoIpUpdateCommand::install($archive, $target))->toThrow(RuntimeException::class);

    expect(file_get_contents($target))->toBe('last good database')
        ->and($target.'.part')->not->toBeFile();
});

it('refuses an archive that holds no database at all', function () {
    $target = MaxMindIpIntelligence::file(MaxMindIpIntelligence::PROXY_DB);
    $archive = zipFixture($this->geoip, 'readme.txt', 'NO PERMISSION');

    expect(fn () => GeoIpUpdateCommand::install($archive, $target))
        ->toThrow(RuntimeException::class, 'no .bin file');
});

/*
 * `IP2Proxy\Database` exposes no `isProxy()` method — only `lookup()`. Calling
 * one that does not exist raised an Error the reader swallowed along with every
 * other lookup failure, so the proxy rule was quietly answering "no" for every
 * address. Nothing caught it because no test had ever opened a real BIN file.
 */
it('reads a proxy verdict out of a real database rather than swallowing the call', function () {
    File::copy(
        base_path('vendor/ip2location/ip2proxy-php/data/PX12.SAMPLE.BIN'),
        MaxMindIpIntelligence::file(MaxMindIpIntelligence::PROXY_DB),
    );

    $intelligence = new MaxMindIpIntelligence;

    // A datacenter range in the sample database, and an address it holds as
    // ordinary consumer traffic.
    expect($intelligence->inspect('15.204.0.1')->isProxy)->toBeTrue()
        ->and($intelligence->inspect('1.0.0.1')->isProxy)->toBeFalse();
});

it('skips a source with no credential instead of failing the schedule', function () {
    config([
        'playndgain.geoip_maxmind_license_key' => null,
        'playndgain.geoip_ip2location_token' => null,
    ]);

    $this->artisan('geoip:update')
        ->expectsOutputToContain('no credential configured')
        ->assertSuccessful();
});
