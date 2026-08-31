<?php

use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Http\Controllers\LandingController;
use App\Models\FraudRule;
use App\Models\Network;
use App\Models\Offer;

/**
 * Invoked directly rather than through the route so the queries behind the
 * counters are exercised against the real schema rather than trusted.
 *
 * Payouts are pinned instead of left to the factory's randomFloat: the average
 * is a figure the payout calculator multiplies by and prints on screen, so the
 * test has to prove which rows it came from, not merely that a number arrived.
 */
it('counts only what it can defend', function () {
    $live = Network::factory()->create(['status' => NetworkStatus::Active]);
    Network::factory()->create(['status' => NetworkStatus::Disabled]);

    Offer::factory()->for($live)->create(['countries' => ['US', 'EG'], 'payout' => 1.25]);
    Offer::factory()->for($live)->create(['countries' => ['EG', 'DE'], 'payout' => 2.75]);
    Offer::factory()->for($live)->create(['status' => OfferStatus::Inactive, 'countries' => ['FR'], 'payout' => 99.00]);

    FraudRule::query()->insert([
        ['key' => 'dupe', 'name' => 'Duplicate', 'description' => 'x', 'stage' => 'click', 'enabled' => true, 'action' => 'block'],
        ['key' => 'geo', 'name' => 'Geo', 'description' => 'x', 'stage' => 'click', 'enabled' => false, 'action' => 'block'],
    ]);

    $stats = app(LandingController::class)()->getData()['stats'];

    expect($stats)->toBe([
        'offers' => 2,
        'countries' => 3,   // US, EG, DE — deduped across the JSON column
        'networks' => 1,
        'fraud_rules' => 1,
        'avg_payout' => 2.0, // the two live offers only; the $99 inactive one is not on the wall
    ]);
});
