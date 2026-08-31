<?php

use App\Enums\ConversionStatus;
use App\Enums\OfferStatus;
use App\Jobs\DispatchPublisherPostback;
use App\Models\Conversion;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Models\Setting;
use App\Services\Wall\TestConversion;
use Database\Seeders\NetworkSeeder;
use Database\Seeders\TestOfferSeeder;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/WallFixtures.php';

/**
 * docs/03-offerwall.md §7 ends with "It is not optional." — the test offer is
 * the feature that turns integration support from a conversation into a
 * self-service action, so these assert the two halves of that claim: it is
 * there without anyone configuring it, and clicking it runs the real pipeline.
 */
function testOfferFixture(): array
{
    // A callback URL, because the assertion that matters here is that the
    // publisher's own endpoint gets called — with none set there is no job to
    // queue and the test would pass by having nothing to do.
    [$app, $network] = wallFixture([
        'test_mode' => true,
        'postback_url' => 'https://publisher.test/callback?trans={trans_id}&payout={payout}&sig={signature}',
    ]);

    $offer = wallOffer($network, ['payout' => '0.010000', 'countries' => [], 'devices' => []]);
    Setting::set('test_offer_id', (string) $offer->id);

    wallGeo('US');

    return [$app, $offer];
}

it('seeds a test offer and points the setting at it', function () {
    $this->seed(NetworkSeeder::class);
    $this->seed(TestOfferSeeder::class);

    $id = Setting::get('test_offer_id');

    expect($id)->toBeNumeric();

    $offer = Offer::query()->find((int) $id);

    // On the `direct` network on purpose: nothing syncs that network, so no
    // catalogue run can deactivate the row for having stopped appearing in a
    // feed it was never in.
    expect($offer)->not->toBeNull()
        ->and($offer->payout)->toBe('0.010000')
        ->and($offer->network->driver)->toBe('direct')
        ->and($offer->status)->toBe(OfferStatus::Active);
});

it('converts the test click through the real pipeline instead of redirecting', function () {
    Queue::fake();

    [$app, $offer] = testOfferFixture();

    wallClick($app, $offer)
        ->assertOk()
        ->assertSee(__('wall.notice.test_fired.title'));

    $conversion = Conversion::query()->firstOrFail();

    expect($conversion->is_test)->toBeTrue()
        ->and($conversion->status)->toBe(ConversionStatus::Approved)
        ->and($conversion->revenue)->toBe('0.010000');

    // The whole point of the feature: the publisher's own endpoint is called,
    // through the same job a network conversion would queue.
    Queue::assertPushed(DispatchPublisherPostback::class);
});

it('keeps the test conversion out of the ledger', function () {
    Queue::fake();

    [$app, $offer] = testOfferFixture();

    wallClick($app, $offer)->assertOk();

    expect(LedgerEntry::query()->count())->toBe(0)
        ->and($app->publisher->fresh()->balance)->toBe('0.000000');
});

it('credits a test click once however many times it is replayed', function () {
    Queue::fake();

    [$app, $offer] = testOfferFixture();

    // Two clicks are two click ids and therefore two conversions — that is
    // correct. What must not double is one click id, which is what a reload of
    // the redirect target would produce.
    wallClick($app, $offer)->assertOk();

    $click = OfferClick::query()->firstOrFail();

    app(TestConversion::class)->fire($click, $offer->fresh());

    expect(Conversion::query()->count())->toBe(1);
});

it('refuses a hand-crafted test-offer click outside test mode', function () {
    Queue::fake();

    [$app, $network] = wallFixture(['test_mode' => false]);
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);
    Setting::set('test_offer_id', (string) $offer->id);
    wallGeo('US');

    // The URL is guessable — the offer id is in the path. Without test mode
    // there is nothing legitimate to do with it: no conversion, and no redirect
    // to a tracking URL that points at our own documentation.
    wallClick($app, $offer)->assertGone();

    expect(Conversion::query()->count())->toBe(0);
});

it('never serves the test offer as ordinary inventory', function () {
    // The row is featured, worldwide, device-agnostic and pays exactly the
    // $0.01 floor. Every one of those would put it at the top of every wall the
    // day an admin enables the `direct` network to sell something real.
    [$app, $network] = wallFixture(['test_mode' => false]);

    $test = wallOffer($network, ['name' => 'Test Offer', 'payout' => '0.010000', 'is_featured' => true, 'countries' => [], 'devices' => []]);
    wallOffer($network, ['name' => 'Real Offer', 'countries' => [], 'devices' => []]);

    Setting::set('test_offer_id', (string) $test->id);
    wallGeo('US');

    wallGet($app)
        ->assertOk()
        ->assertSee('Real Offer')
        ->assertDontSee('Test Offer');
});
