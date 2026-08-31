<?php

use App\Models\Network;
use App\Models\OfferClick;
use Database\Factories\OfferClickFactory;

require_once __DIR__.'/WallFixtures.php';

it('stores a click row with no offer on it', function () {
    [$app, $network] = wallFixture();

    // `offer_id` and `network_id` are both explicit: the factory derives the
    // network from the offer, and there is no offer here to derive it from.
    $click = OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $app->publisher_id,
        'offer_id' => null,
        'network_id' => $network->id,
    ]);

    expect($click->fresh()->offer_id)->toBeNull()
        ->and(OfferClick::query()->count())->toBe(1);
});

it('lists embeddable networks by priority, and only the ones that qualify', function () {
    $low = Network::factory()->create(['wall_url' => 'https://a.test/w?u={sub_id}', 'priority' => 1]);
    $high = Network::factory()->create(['wall_url' => 'https://b.test/w?u={sub_id}', 'priority' => 9]);
    Network::factory()->create(['wall_url' => null, 'priority' => 20]);
    Network::factory()->disabled()->create(['wall_url' => 'https://c.test/w?u={sub_id}', 'priority' => 30]);

    expect(Network::query()->embeddable()->pluck('id')->all())->toBe([$high->id, $low->id]);
});

it('defaults a network to framing rather than a new tab', function () {
    // Read back rather than asserting on the in-memory model: the default
    // lives in the migration, and a factory default would let this pass even
    // if the column shipped without one.
    expect(Network::factory()->create()->fresh()->wall_new_tab)->toBeFalse();
});
