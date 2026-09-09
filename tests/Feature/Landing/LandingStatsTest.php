<?php

use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Http\Controllers\LandingController;
use App\Models\FraudRule;
use App\Models\Network;
use App\Models\Offer;

/**
 * Invoked directly rather than through the route so the one query left behind
 * the page is exercised against the real schema rather than trusted.
 *
 * The page used to print four figures — live offers, countries, active demand
 * partners, callback delivery — and most of this file used to be about the
 * sample floors and fallbacks that kept them honest. They are gone, and gone on
 * purpose: see LandingController's docblock and LandingContent::proofs(). What
 * these tests now hold in place is the property that replaced them, which is
 * that the front door states capabilities and cannot print a count of anything.
 */
it('ships the rule count and no other statistic', function () {
    $live = Network::factory()->create(['status' => NetworkStatus::Active]);
    Network::factory()->create(['status' => NetworkStatus::Disabled]);

    Offer::factory()->for($live)->create(['countries' => ['US', 'EG']]);
    Offer::factory()->for($live)->create(['countries' => ['EG', 'DE']]);
    Offer::factory()->for($live)->create(['status' => OfferStatus::Inactive, 'countries' => ['FR']]);

    FraudRule::query()->insert([
        ['key' => 'dupe', 'name' => 'Duplicate', 'description' => 'x', 'stage' => 'click', 'enabled' => true, 'action' => 'block'],
        ['key' => 'geo', 'name' => 'Geo', 'description' => 'x', 'stage' => 'click', 'enabled' => false, 'action' => 'block'],
    ]);

    // Two live offers across three countries on one active network, and none of
    // those six numbers reaches the page. Only the enabled rules do, because the
    // fraud card names them.
    expect(app(LandingController::class)()->getData()['stats'])->toBe(['fraud_rules' => 1]);
});

it('states capabilities in the proof band rather than counters', function () {
    $live = Network::factory()->create(['status' => NetworkStatus::Active]);
    Offer::factory()->count(13)->for($live)->create(['countries' => ['US', 'EG']]);

    $page = $this->get('/')->assertOk();

    // The four things the band claims, none of which has a denominator.
    $page->assertSee('One integration')
        ->assertSee('Your brand')
        ->assertSee('Signed callbacks')
        ->assertSee('Paid monthly')
        ->assertSee('Clearing on the 1st');

    // And the labels the counters used to carry. "Demand partners live" is the
    // one that matters most: a count of partners on a public surface is an
    // invitation to work out who they are (AGENTS.md).
    $page->assertDontSee('Live offers')
        ->assertDontSee('Countries')
        ->assertDontSee('Demand partners live')
        ->assertDontSee('Callbacks delivered')
        ->assertDontSee('data-metric', false);
});

it('states the terms from the settings table', function () {
    $terms = app(LandingController::class)()->getData()['terms'];

    // Seeded defaults, formatted without trailing zeros: "20", not "20.00".
    // The revenue share is not among them on purpose: it is a per-publisher
    // commercial term and never reaches a public page.
    expect($terms)->toBe([
        'hold_days' => '30',
        'min_payout' => '20',
    ]);
});

it('puts the minimum payout in the proof band and the terms strip, from settings', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('from $20.')
        ->assertSee('Minimum payout');
});
