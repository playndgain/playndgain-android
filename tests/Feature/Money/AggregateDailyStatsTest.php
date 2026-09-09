<?php

use App\Enums\ConversionStatus;
use App\Models\App;
use App\Models\Conversion;
use App\Models\DailyStat;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Services\Reporting\StatsQuery;
use Database\Factories\OfferClickFactory;

/**
 * `daily_stats` is what every chart in both panels reads (docs/06 §7), so what
 * lands in it decides whether two pages can disagree about the same day.
 */
function statsFixture(): array
{
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);
    $network = Network::factory()->create();
    $offer = Offer::factory()->create(['network_id' => $network->id]);

    return [$publisher, $app, $network, $offer];
}

function conversionRow(array $attributes, Publisher $publisher, App $app, Network $network, Offer $offer): Conversion
{
    return Conversion::factory()->create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'country' => 'EG',
        'revenue' => '1.000000',
        'payout' => '0.700000',
        'status' => ConversionStatus::Approved,
        'created_at' => now(),
        ...$attributes,
    ]);
}

it('aggregates clicks, conversions, revenue and payout for a day', function () {
    [$publisher, $app, $network, $offer] = statsFixture();

    OfferClickFactory::new()->count(3)->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
        'country' => 'EG',
        'created_at' => now(),
    ]);

    conversionRow([], $publisher, $app, $network, $offer);

    $this->artisan('stats:aggregate --today')->assertSuccessful();

    $row = DailyStat::query()->withoutGlobalScopes()->firstOrFail();

    expect($row->clicks)->toBe(3)
        ->and($row->conversions)->toBe(1)
        ->and($row->revenue)->toBe('1.000000')
        ->and($row->payout)->toBe('0.700000');
});

it('keeps test traffic out of every reporting figure', function () {
    [$publisher, $app, $network, $offer] = statsFixture();

    OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
        'is_test' => true,
        'created_at' => now(),
    ]);

    conversionRow(['is_test' => true], $publisher, $app, $network, $offer);

    $this->artisan('stats:aggregate --today')->assertSuccessful();

    expect(DailyStat::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('counts a reversal separately and strips its revenue from the day', function () {
    [$publisher, $app, $network, $offer] = statsFixture();

    conversionRow([], $publisher, $app, $network, $offer);
    conversionRow([
        'status' => ConversionStatus::Reversed,
        'reversed_at' => now(),
    ], $publisher, $app, $network, $offer);

    $this->artisan('stats:aggregate --today')->assertSuccessful();

    $row = DailyStat::query()->withoutGlobalScopes()->firstOrFail();

    expect($row->conversions)->toBe(1)
        ->and($row->reversals)->toBe(1)
        ->and($row->revenue)->toBe('1.000000');
});

it('rebuilds rather than duplicating when run twice', function () {
    [$publisher, $app, $network, $offer] = statsFixture();

    conversionRow([], $publisher, $app, $network, $offer);

    $this->artisan('stats:aggregate --today')->assertSuccessful();
    $this->artisan('stats:aggregate --today')->assertSuccessful();

    // The unique index spans two nullable columns, so an upsert would silently
    // double every row with no country. This is the assertion that catches it.
    expect(DailyStat::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('reads back through StatsQuery with the same numbers', function () {
    [$publisher, $app, $network, $offer] = statsFixture();

    conversionRow([], $publisher, $app, $network, $offer);

    $this->artisan('stats:aggregate --today')->assertSuccessful();

    $totals = StatsQuery::make()
        ->forPublisher($publisher->id)
        ->between(now()->toImmutable()->startOfDay(), now()->toImmutable())
        ->totals();

    expect($totals['conversions'])->toBe(1)
        ->and($totals['revenue'])->toBe('1.000000')
        ->and($totals['payout'])->toBe('0.700000')
        ->and($totals['profit'])->toBe('0.300000');
});

it('does not leak another publisher\'s numbers into a scoped read', function () {
    [$publisher, $app, $network, $offer] = statsFixture();
    [$other, $otherApp] = statsFixture();

    conversionRow([], $publisher, $app, $network, $offer);
    conversionRow([], $other, $otherApp, $network, $offer);

    $this->artisan('stats:aggregate --today')->assertSuccessful();

    $totals = StatsQuery::make()
        ->forPublisher($publisher->id)
        ->between(now()->toImmutable()->startOfDay(), now()->toImmutable())
        ->totals();

    expect($totals['conversions'])->toBe(1);
});

it('scopes StatsQuery to one network', function () {
    [$publisher, $app, $network, $offer] = statsFixture();
    $other = Network::factory()->create();
    $otherOffer = Offer::factory()->create(['network_id' => $other->id]);

    conversionRow([], $publisher, $app, $network, $offer);
    conversionRow([], $publisher, $app, $other, $otherOffer);

    $this->artisan('stats:aggregate --today')->assertSuccessful();

    $totals = StatsQuery::make()
        ->forNetwork($network->id)
        ->between(now()->toImmutable()->startOfDay(), now()->toImmutable())
        ->totals();

    expect($totals['conversions'])->toBe(1);
});
