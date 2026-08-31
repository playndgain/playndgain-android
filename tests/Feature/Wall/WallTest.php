<?php

use App\Enums\AppStatus;
use App\Enums\ConversionStatus;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Enums\PublisherStatus;
use App\Models\Conversion;
use App\Models\Network;
use App\Support\AppNetworks;
use Database\Factories\BannedEndUserFactory;
use Database\Factories\BlacklistFactory;
use Database\Factories\ConsentFactory;

require_once __DIR__.'/WallFixtures.php';

it('404s an app id that does not resolve', function () {
    test()->get('/wall?appid=99999&userid=USER_1')->assertNotFound();
    test()->get('/wall?userid=USER_1')->assertNotFound();
});

it('shows the unavailable page for a paused app or an unapproved publisher', function () {
    [$paused] = wallFixture(['status' => AppStatus::Paused]);
    [$pending] = wallFixture([], ['status' => PublisherStatus::Pending]);

    wallGet($paused)->assertStatus(503)->assertSee(__('wall.notice.unavailable.title'));
    wallGet($pending)->assertStatus(503);
});

it('rejects a malformed userid with the notice page, not an exception page', function () {
    [$app] = wallFixture();

    wallGet($app, 'has space')
        ->assertStatus(400)
        ->assertSee(__('wall.notice.invalid.title'));

    test()->get('/wall?appid='.$app->app_id)->assertStatus(400);
    test()->get('/wall?appid='.$app->app_id.'&userid=%3C%D8%B3%3E')
        ->assertStatus(400)
        ->assertSee(__('wall.notice.invalid.title'));
});

it('403s an invalid signature on a signed app and serves a valid one', function () {
    [$app, $network] = wallFixture(['require_signed_wall' => true]);
    wallOffer($network, ['countries' => []]);
    wallGeo('US');

    wallGet($app, 'USER_1', ['sig' => 'deadbeef'])->assertForbidden();
    wallGet($app, 'USER_1')->assertForbidden();
    wallGet($app, 'USER_1', ['sig' => wallSig($app)])->assertOk();
});

it('never shows an offer that excludes the resolved country', function () {
    [$app, $network] = wallFixture();
    wallGeo('DE');

    $excluded = wallOffer($network, ['name' => 'US Only Offer', 'countries' => ['US']]);
    $included = wallOffer($network, ['name' => 'German Offer', 'countries' => ['DE']]);

    wallGet($app)
        ->assertOk()
        ->assertDontSee($excluded->name)
        ->assertSee($included->name);
});

it('serves nothing at all when the country cannot be resolved', function () {
    [$app, $network] = wallFixture();
    wallGeo(null);

    $targeted = wallOffer($network, ['name' => 'Targeted Offer', 'countries' => ['US', 'DE']]);
    $worldwide = wallOffer($network, ['name' => 'Worldwide Offer', 'countries' => []]);

    // Fail closed all the way. A worldwide offer is open to every country, but
    // serving one to an address we could not place is still serving an offer
    // to a user whose eligibility was never established — and the screen says
    // so rather than blaming a country it does not know.
    wallGet($app)
        ->assertOk()
        ->assertDontSee($targeted->name)
        ->assertDontSee($worldwide->name)
        ->assertSee(__('wall.unplaced.title'))
        ->assertDontSee(__('wall.empty.title'));
});

it('serves nothing to a user agent that parses to no device', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    // A UA we cannot bucket is the device half of the same rule.
    test()->withHeaders(['User-Agent' => 'curl/8.4.0'])->get(wallUrl($app))
        ->assertOk()
        ->assertDontSee($offer->name)
        ->assertSee(__('wall.unplaced.title'));

    wallGet($app)->assertOk()->assertSee($offer->name);
});

it('honours the country override only while the app is in test mode', function () {
    // The `country` parameter is a targeting override. On a live app it would
    // be a way to be served exactly the offers the targeting refused, so the
    // ignored case is the one worth asserting — docs/03 §7.
    [$live, $network] = wallFixture(['test_mode' => false]);
    [$testing] = wallFixture(['test_mode' => true], driver: $network->driver);
    wallGeo(null);

    $german = wallOffer($network, ['name' => 'German Offer', 'countries' => ['DE']]);

    wallGet($live, extra: ['country' => 'DE'])->assertOk()->assertDontSee($german->name);
    wallGet($testing, extra: ['country' => 'DE'])->assertOk()->assertSee($german->name);
});

it('filters on device, network status, offer status and the payout floor', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $desktop = wallOffer($network, ['name' => 'Desktop Only', 'countries' => [], 'devices' => ['desktop']]);
    $inactive = wallOffer($network, ['name' => 'Inactive Offer', 'countries' => [], 'status' => OfferStatus::Inactive]);
    $subCent = wallOffer($network, ['name' => 'Sub Cent Offer', 'countries' => [], 'payout' => 0.009]);
    $shown = wallOffer($network, ['name' => 'Android Offer', 'countries' => [], 'devices' => ['android']]);

    $disabled = wallOffer(
        Network::factory()->create(['status' => NetworkStatus::Disabled]),
        ['name' => 'Disabled Network Offer', 'countries' => [], 'devices' => []],
    );

    wallGet($app)
        ->assertOk()
        ->assertSee($shown->name)
        ->assertDontSee($desktop->name)
        ->assertDontSee($inactive->name)
        ->assertDontSee($subCent->name)
        ->assertDontSee($disabled->name);
});

it('hides a blacklisted offer for that app but not for another', function () {
    [$app, $network] = wallFixture();
    [$other] = wallFixture();
    wallGeo('US');

    $blocked = wallOffer($network, ['name' => 'Blocked Offer', 'countries' => [], 'devices' => []]);
    $fine = wallOffer($network, ['name' => 'Allowed Offer', 'countries' => [], 'devices' => []]);

    BlacklistFactory::new()->offer($blocked->id)->create(['publisher_id' => $app->publisher_id]);

    wallGet($app)->assertOk()->assertDontSee($blocked->name)->assertSee($fine->name);
    wallGet($other)->assertOk()->assertSee($blocked->name)->assertSee($fine->name);
});

it('hides a blacklisted network for that app but not for another', function () {
    [$app, $network] = wallFixture();
    [$other] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, ['name' => 'Networked Offer', 'countries' => [], 'devices' => []]);

    BlacklistFactory::new()->network($network->driver)->create(['publisher_id' => $app->publisher_id]);

    wallGet($app)->assertOk()->assertDontSee($offer->name);
    wallGet($other)->assertOk()->assertSee($offer->name);
});

it('hides a network turned off on the app, whether the row stores an id or a driver', function () {
    [$app, $network] = wallFixture();
    [$other] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, ['name' => 'Toggled Offer', 'countries' => [], 'devices' => []]);

    AppNetworks::sync($app, []);

    wallGet($app)->assertOk()->assertDontSee($offer->name);
    wallGet($other)->assertOk()->assertSee($offer->name);
});

// A blacklisted country or device empties the wall — every offer fails the same
// check, so the empty state names the cause instead of the filter.
it('empties the wall for a blacklisted country but not for another app', function () {
    [$app, $network] = wallFixture();
    [$other] = wallFixture();
    wallGeo('US');
    $offer = wallOffer($network, ['name' => 'Country Offer', 'countries' => [], 'devices' => []]);

    BlacklistFactory::new()->country('US')->create(['publisher_id' => $app->publisher_id]);

    wallGet($app)->assertOk()->assertSee(__('wall.empty.title'))->assertDontSee($offer->name);
    wallGet($other)->assertOk()->assertSee($offer->name);
});

it('empties the wall for a blacklisted device but not for another app', function () {
    [$app, $network] = wallFixture();
    [$other] = wallFixture();
    wallGeo('US');
    $offer = wallOffer($network, ['name' => 'Device Offer', 'countries' => [], 'devices' => []]);

    BlacklistFactory::new()->device('android')->create(['publisher_id' => $app->publisher_id]);

    wallGet($app)->assertOk()->assertSee(__('wall.empty.title'))->assertDontSee($offer->name);
    wallGet($other)->assertOk()->assertSee($offer->name);
});

it('honours the app blocked_countries list', function () {
    [$app, $network] = wallFixture(['blocked_countries' => ['DE']]);
    wallGeo('DE');

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallGet($app)->assertOk()->assertDontSee($offer->name)->assertSee(__('wall.empty.title'));
});

it('drops a completed offer for that user and keeps it for another', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $done = wallOffer($network, ['name' => 'Completed Offer', 'countries' => [], 'devices' => []]);
    $repeatable = wallOffer($network, ['name' => 'Repeatable Offer', 'countries' => [], 'devices' => [], 'is_multi_reward' => true]);

    foreach ([$done, $repeatable] as $offer) {
        Conversion::factory()->create([
            'app_id' => $app->id,
            'publisher_id' => $app->publisher_id,
            'offer_id' => $offer->id,
            'network_id' => $network->id,
            'end_user_id' => 'USER_1',
            'status' => ConversionStatus::Approved,
        ]);
    }

    wallGet($app, 'USER_1')->assertOk()->assertDontSee($done->name)->assertSee($repeatable->name);
    wallGet($app, 'USER_2')->assertOk()->assertSee($done->name);
});

it('shows the reward in the publisher currency and never the USD payout', function () {
    // $0.50 × 70% rev-share × 1000 coins per dollar = 350 coins.
    [$app, $network] = wallFixture(['currency_name' => 'Coins', 'currency_rate' => 1000], ['rev_share' => 70]);
    wallGeo('US');

    wallOffer($network, ['name' => 'Half Dollar Offer', 'payout' => 0.50, 'countries' => [], 'devices' => []]);

    wallGet($app)
        ->assertOk()
        ->assertSee('350')
        ->assertSee('Coins')
        ->assertDontSee('0.50')
        ->assertDontSee('$');
});

it('shows the unavailable screen to a banned end user and serves an expired ban', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    BannedEndUserFactory::new()->create(['publisher_id' => $app->publisher_id, 'end_user_id' => 'USER_1']);
    BannedEndUserFactory::new()->expired()->create(['publisher_id' => $app->publisher_id, 'end_user_id' => 'USER_2']);

    wallGet($app, 'USER_1')->assertOk()->assertSee(__('wall.notice.banned.title'))->assertDontSee($offer->name);
    wallGet($app, 'USER_2')->assertOk()->assertSee($offer->name);
});

it('gates a consent-required country and remembers the answer per app and user', function () {
    [$app, $network] = wallFixture();
    wallConsentCountries(['DE']);
    wallGeo('DE');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallGet($app)->assertOk()->assertSee(__('wall.consent.title'))->assertDontSee($offer->name);

    ConsentFactory::new()->declined()->create(['app_id' => $app->id, 'end_user_id' => 'USER_1']);
    wallGet($app)->assertOk()->assertSee(__('wall.notice.declined.title'))->assertDontSee($offer->name);

    ConsentFactory::new()->create(['app_id' => $app->id, 'end_user_id' => 'USER_2']);
    wallGet($app, 'USER_2')->assertOk()->assertSee($offer->name);
});

it('reopens the gate for a user who wants to change their answer', function () {
    [$app] = wallFixture();
    wallConsentCountries(['DE']);
    wallGeo('DE');

    ConsentFactory::new()->declined()->create(['app_id' => $app->id, 'end_user_id' => 'USER_1']);

    wallGet($app, 'USER_1', ['consent' => 'review'])->assertOk()->assertSee(__('wall.consent.title'));
});

it('never lets the wall html be cached', function () {
    [$app] = wallFixture();
    wallGeo('US');

    wallGet($app)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('renders the publisher theme as custom properties and drops junk values', function () {
    [$app] = wallFixture(['theme' => ['primary' => '#00FF88', 'background' => '#111111', 'card' => 'red; } body { display:none', 'radius' => '4px']]);
    wallGeo('US');

    wallGet($app)
        ->assertOk()
        ->assertSee('class="wall-pane"', false)
        ->assertSee('--wall-primary:#00FF88', false)
        ->assertSee('--wall-radius:4px', false)
        // Derived, not configured: a bright mint accent has to be labelled in
        // near-black or every filled pill on the wall is unreadable.
        ->assertSee('--wall-on-primary:#0B0D10', false)
        ->assertDontSee('display:none', false);
});

it('emits a legible foreground for whatever accent the publisher picked', function () {
    // The wall's chrome is monochrome, but the accent pair is still part of
    // the emitted theme contract, and the rule stands on its own: a dark
    // primary pairs with a white foreground, a light one with near-black.
    [$dark] = wallFixture(['theme' => ['primary' => '#3B1D8F']]);
    [$light] = wallFixture(['theme' => ['primary' => '#FFE066']]);
    wallGeo('US');

    wallGet($dark)->assertOk()->assertSee('--wall-on-primary:#FFFFFF', false);
    wallGet($light)->assertOk()->assertSee('--wall-on-primary:#0B0D10', false);
});

it('serves the arabic wall right to left', function () {
    [$app] = wallFixture();
    wallGeo('US');

    wallGet($app, 'USER_1', ['lang' => 'ar'])
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('wall.title', [], 'ar'), false);
});
