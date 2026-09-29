<?php

use App\Models\Network;

/**
 * RefreshDatabase has already run this migration against an empty table, so
 * the pre-move row is seeded afterwards and the migration invoked by hand.
 */
beforeEach(function () {
    $this->migration = require database_path('migrations/2026_09_29_000002_move_ayetstudios_to_offerwall_api_credentials.php');
});

it('drops the dead Static API key and fills the verified rate', function () {
    $network = Network::factory()->create([
        'driver' => 'ayetstudios',
        'credentials' => ['adslot_id' => '10000', 'api_key' => 'static-key', 'publisher_api_key' => 'pub-key'],
    ]);

    $this->migration->up();
    $this->migration->up();

    expect($network->fresh()->credentials)
        ->toEqual(['adslot_id' => '10000', 'publisher_api_key' => 'pub-key', 'coins_per_usd' => '1000']);
});

it('keeps a rate an admin already typed', function () {
    $network = Network::factory()->create([
        'driver' => 'ayetstudios',
        'credentials' => ['adslot_id' => '10000', 'coins_per_usd' => '250'],
    ]);

    $this->migration->up();

    expect($network->fresh()->credentials['coins_per_usd'])->toBe('250');
});

it('zeroes the Static API offer count so the collapse guard does not trip', function () {
    $network = Network::factory()->create([
        'driver' => 'ayetstudios',
        'credentials' => ['adslot_id' => '10000'],
        'offers_count' => 900,
    ]);

    $this->migration->up();

    expect($network->fresh()->offers_count)->toBe(0);
});

it('leaves other networks alone', function () {
    $other = Network::factory()->create(['driver' => 'monlix', 'credentials' => ['api_key' => 'k', 'secret_key' => 's']]);

    $this->migration->up();

    expect($other->fresh()->credentials)->toEqual(['api_key' => 'k', 'secret_key' => 's']);
});
