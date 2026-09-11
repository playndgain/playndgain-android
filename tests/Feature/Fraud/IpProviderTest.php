<?php

use App\Models\IpProvider;
use Database\Seeders\IpProviderSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

it('encrypts the api key at rest', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'api_key' => 'secret-key',
    ]);

    expect($provider->fresh()->api_key)->toBe('secret-key')
        ->and(DB::table('ip_providers')->where('id', $provider->id)->value('api_key'))
        ->not->toBe('secret-key');
});

/**
 * A key written under a previous APP_KEY is ciphertext we can no longer read.
 * Throwing here takes down every caller — the Protection page and the wall
 * chain — for a secret that is already unusable. Treat it as missing so the
 * operator can paste a new one and traffic keeps the local verdict.
 */
it('treats a key encrypted under another app key as missing', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'P', 'enabled' => true,
    ]);

    $foreign = new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher'));

    DB::table('ip_providers')->where('id', $provider->id)->update([
        'api_key' => $foreign->encryptString('lost-key'),
    ]);

    $fresh = $provider->fresh();

    expect($fresh->api_key)->toBeNull()
        ->and($fresh->available())->toBeFalse();

    expect(IpProvider::active())->toHaveCount(0);
});

it('is unavailable until it is enabled and has a key', function () {
    $provider = IpProvider::query()->create(['key' => 'proxycheck', 'name' => 'P']);

    expect($provider->available())->toBeFalse();

    $provider->update(['enabled' => true]);
    expect($provider->available())->toBeFalse();

    $provider->update(['api_key' => 'k']);
    expect($provider->fresh()->available())->toBeTrue();
});

it('is unavailable while disabled, even fully configured otherwise', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'P', 'enabled' => false, 'api_key' => 'k',
    ]);

    expect($provider->available())->toBeFalse();
});

it('clears the last error when a lookup succeeds after a failure', function () {
    $provider = IpProvider::query()->create([
        'key' => 'ipqualityscore', 'name' => 'IPQualityScore', 'enabled' => true, 'api_key' => 'k',
    ]);

    $provider->recordFailure('ipqualityscore: Invalid or unauthorized key. Please check the API key and try again.');

    expect($provider->fresh()->last_error)->not->toBeNull()
        ->and($provider->fresh()->last_error_at)->not->toBeNull();

    $provider->recordSuccess();

    expect($provider->fresh()->last_error)->toBeNull()
        ->and($provider->fresh()->last_error_at)->toBeNull();
});

it('opens the breaker after consecutive failures and closes it after the cooldown', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'P', 'enabled' => true, 'api_key' => 'k',
        'circuit_failures' => 2, 'circuit_cooldown_minutes' => 10,
    ]);

    $provider->recordFailure('timeout');
    expect($provider->breakerOpen())->toBeFalse();

    $provider->recordFailure('timeout');
    expect($provider->breakerOpen())->toBeTrue()
        ->and($provider->last_error)->toBe('timeout')
        ->and($provider->available())->toBeFalse();

    // One success is enough: the breaker guards against a provider that is
    // down, not against one that is occasionally slow.
    $this->travel(11)->minutes();
    expect($provider->fresh()->breakerOpen())->toBeFalse();
});

it('stops being available once the daily cap is spent', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'P', 'enabled' => true, 'api_key' => 'k', 'daily_cap' => 2,
    ]);

    $provider->recordSuccess();
    $provider->recordSuccess();

    expect($provider->spent())->toBe(2)
        ->and($provider->available())->toBeFalse();
});

/**
 * `capSpent()` treats a null `daily_cap` as uncapped, so a seeded row with no
 * cap is a provider ordinary render traffic can escalate against forever —
 * the bug Fix 1 closes. Both rows must come out of the seeder with a real
 * ceiling, including providers added after the original two.
 */
it('seeds a non-null daily cap on every provider', function () {
    (new IpProviderSeeder)->run();

    expect(IpProvider::query()->orderBy('priority')->pluck('daily_cap', 'key')->all())
        ->toBe(['proxycheck' => 1000, 'ipasis' => 1000, 'ipqualityscore' => 200]);
});

/**
 * A 200/day cap is 200 *distinct new* addresses, so how long an answer is kept
 * decides how far it reaches. A vendor fraud score moves on weekly timescales;
 * at the one-day default the same address is re-billed daily for an answer that
 * did not change. Seven days turns 200/day into roughly 1,400 addresses of
 * standing coverage. Asserted because it is a cost decision, not a default.
 */
it('holds the scarcer provider answer for a week', function () {
    (new IpProviderSeeder)->run();

    expect(IpProvider::query()->orderBy('priority')->pluck('ttl_seconds', 'key')->all())
        ->toBe(['proxycheck' => 86400, 'ipasis' => 86400, 'ipqualityscore' => 604800]);
});

it('seeds ipasis between proxycheck and ipqualityscore', function () {
    (new IpProviderSeeder)->run();

    expect(IpProvider::query()->orderBy('priority')->pluck('key')->all())
        ->toBe(['proxycheck', 'ipasis', 'ipqualityscore']);
});

it('adds ipasis to an existing installation without changing existing providers', function () {
    IpProvider::query()->where('key', 'ipasis')->delete();

    $proxycheck = IpProvider::query()->create([
        'key' => 'proxycheck',
        'name' => 'ProxyCheck.io',
        'priority' => 42,
    ]);

    $migration = require database_path('migrations/2026_09_10_000001_add_ipasis_ip_provider.php');
    $migration->up();

    expect(IpProvider::query()->where('key', 'ipasis')->sole())
        ->name->toBe('IPASIS')
        ->priority->toBe(15)
        ->daily_cap->toBe(1000)
        ->ttl_seconds->toBe(86400)
        ->enabled->toBeFalse()
        ->and($proxycheck->fresh()->priority)->toBe(42);
});

it('orders active providers by priority and excludes the unavailable', function () {
    IpProvider::query()->create(['key' => 'a', 'name' => 'A', 'enabled' => true, 'api_key' => 'k', 'priority' => 20]);
    IpProvider::query()->create(['key' => 'b', 'name' => 'B', 'enabled' => true, 'api_key' => 'k', 'priority' => 10]);
    IpProvider::query()->create(['key' => 'c', 'name' => 'C', 'enabled' => false, 'api_key' => 'k', 'priority' => 1]);

    expect(IpProvider::active()->pluck('key')->all())->toBe(['b', 'a']);
});
