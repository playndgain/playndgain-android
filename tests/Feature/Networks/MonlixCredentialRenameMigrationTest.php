<?php

use App\Models\Network;
use App\Networks\Drivers\MonlixDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * RefreshDatabase has already run this migration against an empty `networks`
 * table, so the old-style row is seeded afterwards and the migration is invoked
 * by hand — which is also the only way to run it twice and prove it settles.
 */
beforeEach(function () {
    $this->migration = require database_path('migrations/2026_01_06_000001_move_renamed_monlix_credentials.php');

    $this->network = Network::factory()->create([
        'driver' => 'monlix',
        'credentials' => ['app_id' => '8812', 'secret' => 'monlix-secret'],
    ]);

    $this->postback = [
        'transaction_id' => 'MNX-77120',
        'user_id' => 'end-user-42',
        'payout' => '12.750000',
        'secret' => 'monlix-secret',
    ];
});

it('leaves the driver unable to earn until it runs', function () {
    Http::fake(['api.monlix.com/*' => Http::response(['campaigns' => []])]);

    $driver = new MonlixDriver($this->network);

    iterator_to_array($driver->fetchOffers(), false);

    // Empty auth on the catalogue pull, and every inbound conversion refused —
    // the two halves of the bug this migration exists for.
    Http::assertSent(fn ($request) => $request['appid'] === '' && $request['token'] === md5(''));

    expect($driver->verifyPostback(Request::create('/postback/monlix', 'GET', $this->postback)))->toBeFalse();
});

it('moves the renamed keys so the driver authenticates and verifies again', function () {
    Http::fake(['api.monlix.com/*' => Http::response(['campaigns' => []])]);

    $this->migration->up();

    expect($this->network->fresh()->credentials)
        ->toEqual(['api_key' => '8812', 'secret_key' => 'monlix-secret']);

    $driver = new MonlixDriver($this->network->fresh());

    iterator_to_array($driver->fetchOffers(), false);

    Http::assertSent(fn ($request) => $request['appid'] === '8812'
        && $request['token'] === md5('8812monlix-secret'));

    expect($driver->verifyPostback(Request::create('/postback/monlix', 'GET', $this->postback)))->toBeTrue();
});

it('settles — a second run changes nothing', function () {
    $this->migration->up();
    $this->migration->up();

    expect($this->network->fresh()->credentials)
        ->toEqual(['api_key' => '8812', 'secret_key' => 'monlix-secret']);
});

it('never overwrites credentials an admin has already retyped', function () {
    $this->network->update(['credentials' => [
        'app_id' => '8812',
        'secret' => 'monlix-secret',
        'api_key' => '9930',
        'secret_key' => 'retyped-by-hand',
    ]]);

    $this->migration->up();

    // The hand-entered pair survives, and the stale keys go with it so the
    // migration has nothing left to find.
    expect($this->network->fresh()->credentials)
        ->toEqual(['api_key' => '9930', 'secret_key' => 'retyped-by-hand']);
});

it('does not touch a row that never held the old keys', function () {
    $this->network->update(['credentials' => ['api_key' => '9930', 'secret_key' => 'already-new']]);

    $this->migration->up();

    expect($this->network->fresh()->credentials)->toEqual(['api_key' => '9930', 'secret_key' => 'already-new']);
});

it('survives a network with no credentials at all', function () {
    $this->network->update(['credentials' => null]);

    $this->migration->up();

    expect($this->network->fresh()->credentials)->toBeNull();
});

it("leaves Notik's own app_id alone", function () {
    // Notik's *new* schema legitimately owns an `app_id`. An unscoped rename
    // would destroy a credential an admin had just entered correctly.
    $notik = Network::factory()->create([
        'driver' => 'notik',
        'credentials' => ['api_key' => 'notik-key', 'pub_id' => '4412', 'app_id' => '77120', 'postback_secret' => 's'],
    ]);

    $this->migration->up();

    expect($notik->fresh()->credentials)
        ->toEqual(['api_key' => 'notik-key', 'pub_id' => '4412', 'app_id' => '77120', 'postback_secret' => 's']);
});

it('reverses itself on rollback', function () {
    $this->migration->up();
    $this->migration->down();

    expect($this->network->fresh()->credentials)
        ->toEqual(['app_id' => '8812', 'secret' => 'monlix-secret']);
});
