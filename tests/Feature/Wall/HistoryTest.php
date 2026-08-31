<?php

use App\Enums\AppStatus;
use App\Enums\ConversionStatus;
use App\Models\App;
use App\Models\Consent;
use App\Models\Conversion;
use App\Models\Network;
use App\Models\Offer;
use App\Models\OfferClick;
use Database\Factories\BannedEndUserFactory;
use Database\Factories\ConsentFactory;
use Database\Factories\OfferClickFactory;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/WallFixtures.php';

function historyGet(App $app, string $user = 'USER_1', array $extra = []): TestResponse
{
    $query = http_build_query(['appid' => $app->app_id, 'userid' => $user, ...$extra]);

    return test()->withHeaders(['User-Agent' => WALL_UA])->get("/wall/history?{$query}");
}

function historyClick(App $app, Offer $offer, string $user = 'USER_1', array $attributes = []): OfferClick
{
    return OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $app->publisher_id,
        'offer_id' => $offer->id,
        'network_id' => $offer->network_id,
        'end_user_id' => $user,
        ...$attributes,
    ]);
}

it('runs the full gate before it shows anyone their own history', function () {
    wallConsentCountries(['DE']);
    wallGeo('DE');
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);
    historyClick($app, $offer);

    // The history of a user is exactly the data a declined consent refuses, so
    // a deep link must not walk past the gate.
    historyGet($app)->assertOk()
        ->assertSee(__('wall.consent.title'))
        ->assertDontSee($offer->name);

    ConsentFactory::new()->declined()->create(['app_id' => $app->id, 'end_user_id' => 'USER_1']);

    historyGet($app)->assertOk()
        ->assertSee(__('wall.notice.declined.title'))
        ->assertDontSee($offer->name);

    // `consents` is unique on (app_id, end_user_id): a user changes their
    // answer, they do not get a second one.
    Consent::query()->where('app_id', $app->id)->update(['granted' => true]);

    historyGet($app)->assertOk()->assertSee($offer->name);
});

it('refuses history to a banned end user and to an unservable app', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);
    historyClick($app, $offer);

    BannedEndUserFactory::new()->create(['publisher_id' => $app->publisher_id, 'end_user_id' => 'USER_1']);

    historyGet($app)->assertOk()
        ->assertSee(__('wall.notice.banned.title'))
        ->assertDontSee($offer->name);

    [$paused] = wallFixture(['status' => AppStatus::Paused]);

    historyGet($paused)->assertStatus(503)->assertSee(__('wall.notice.unavailable.title'));
});

it('never caches a page that is per end user by definition', function () {
    wallGeo('US');
    [$app] = wallFixture();

    historyGet($app)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('leaves the partner embed row out of the history list', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['name' => 'Real Offer', 'countries' => [], 'devices' => []]);

    historyClick($app, $offer);

    // The embed row: one per (app, partner, end user), reused on every later
    // open, so its `created_at` is the first open and never moves. Rendered
    // here it is a row with no offer name and a timestamp that lies.
    OfferClick::query()->create([
        'app_id' => $app->id,
        'publisher_id' => $app->publisher_id,
        'offer_id' => null,
        'network_id' => Network::factory()->create()->id,
        'end_user_id' => 'USER_1',
        'ip' => '203.0.113.9',
        'created_at' => now()->subDay(),
    ]);

    $response = historyGet($app)->assertOk()->assertSee($offer->name);

    expect(substr_count($response->getContent(), 'wall-history__row'))->toBe(1);
});

it('pairs a click with its conversions and sums what cleared', function () {
    wallGeo('US');
    [$app, $network] = wallFixture(['currency_name' => 'Coins']);

    $started = wallOffer($network, ['name' => 'Untouched Offer', 'countries' => [], 'devices' => []]);
    $credited = wallOffer($network, ['name' => 'Paid Offer', 'countries' => [], 'devices' => []]);
    $multi = wallOffer($network, ['name' => 'Two Goal Offer', 'countries' => [], 'devices' => [], 'is_multi_reward' => true]);

    historyClick($app, $started);
    $paid = historyClick($app, $credited);
    $both = historyClick($app, $multi);

    Conversion::factory()->create([
        'app_id' => $app->id, 'publisher_id' => $app->publisher_id,
        'offer_id' => $credited->id, 'network_id' => $network->id,
        'offer_click_id' => $paid->id, 'end_user_id' => 'USER_1',
        'status' => ConversionStatus::Approved, 'currency_amount' => 120,
    ]);

    // One click, two goals. `hasOne` would show the first row it happened to
    // load and hide the rest of the money.
    foreach ([40, 60] as $amount) {
        Conversion::factory()->create([
            'app_id' => $app->id, 'publisher_id' => $app->publisher_id,
            'offer_id' => $multi->id, 'network_id' => $network->id,
            'offer_click_id' => $both->id, 'end_user_id' => 'USER_1',
            'status' => ConversionStatus::Approved, 'currency_amount' => $amount,
        ]);
    }

    historyGet($app)->assertOk()
        ->assertSee(__('wall.history.status.started'))
        ->assertSee('+120')
        ->assertSee('+100')
        ->assertSee(__('wall.history.status.approved'))
        // A currency-only surface. A `$` here is indistinguishable from a USD
        // payout leaking out of the ledger.
        ->assertDontSee('$');
});

it('names the newest status and carries a rejection reason', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['name' => 'Refused Offer', 'countries' => [], 'devices' => []]);
    $click = historyClick($app, $offer);

    Conversion::factory()->create([
        'app_id' => $app->id, 'publisher_id' => $app->publisher_id,
        'offer_id' => $offer->id, 'network_id' => $network->id,
        'offer_click_id' => $click->id, 'end_user_id' => 'USER_1',
        'status' => ConversionStatus::Rejected, 'rejected_reason' => 'Duplicate device',
    ]);

    historyGet($app)->assertOk()
        ->assertSee(__('wall.history.status.rejected'))
        ->assertSee('Duplicate device')
        // Rejected is a status, not a balance: `0` under it reads like money.
        ->assertDontSee(__('wall.history.status.approved'));
});

it('shows a user only their own clicks, on only this app', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    [$other] = wallFixture(driver: $network->driver);

    $mine = wallOffer($network, ['name' => 'My Offer', 'countries' => [], 'devices' => []]);
    $theirs = wallOffer($network, ['name' => 'Their Offer', 'countries' => [], 'devices' => []]);
    $elsewhere = wallOffer($network, ['name' => 'Other App Offer', 'countries' => [], 'devices' => []]);

    historyClick($app, $mine, 'USER_1');
    historyClick($app, $theirs, 'USER_2');
    historyClick($other, $elsewhere, 'USER_1');

    // Test rows are the publisher's own integration testing, not a history.
    historyClick($app, $theirs, 'USER_1', ['is_test' => true]);

    historyGet($app, 'USER_1')->assertOk()
        ->assertSee($mine->name)
        ->assertDontSee($theirs->name)
        ->assertDontSee($elsewhere->name);
});

it('names the cause when there is nothing to show', function () {
    wallGeo('US');
    [$app] = wallFixture();

    historyGet($app)->assertOk()
        ->assertSee(__('wall.history.empty.title'))
        ->assertSee(__('wall.history.empty.action'))
        ->assertSee('href="#ic-clock"', false);
});

/** The row a partner's postback resolves back to: one per (app, partner, end user). */
function historyEmbed(App $app, Network $partner, string $user = 'USER_1'): OfferClick
{
    return OfferClick::query()->create([
        'app_id' => $app->id,
        'publisher_id' => $app->publisher_id,
        'offer_id' => null,
        'network_id' => $partner->id,
        'end_user_id' => $user,
        'ip' => '203.0.113.9',
        // Frozen at first open, months before anything was earned on it.
        'created_at' => now()->subMonths(2),
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function historyPartnerConversion(OfferClick $embed, string $name, array $attributes = []): Conversion
{
    return Conversion::factory()->create([
        'app_id' => $embed->app_id,
        'publisher_id' => $embed->publisher_id,
        // An embedded partner wall has no offer of ours, so both are explicit:
        // the factory resolves `network_id` off `offer_id` and cannot here.
        'offer_id' => null,
        'network_id' => $embed->network_id,
        'offer_click_id' => $embed->id,
        'end_user_id' => $embed->end_user_id,
        'offer_name' => $name,
        'status' => ConversionStatus::Approved,
        'currency_amount' => 50,
        'created_at' => now(),
        ...$attributes,
    ]);
}

it('shows what an embedded partner wall paid, named and timed by its own postback', function () {
    wallGeo('US');
    [$app, $network] = wallFixture(['currency_name' => 'Coins']);
    [$other] = wallFixture(driver: $network->driver);
    $partner = Network::factory()->create(['name' => 'MyChips', 'driver' => 'mychips']);

    $mine = historyEmbed($app, $partner);
    $theirs = historyEmbed($app, $partner, 'USER_2');
    $elsewhere = historyEmbed($other, $partner);

    historyPartnerConversion($mine, 'micro_reward');
    // A multi-reward partner offer fires again on the same embed row, and the
    // second payout is its own row — not a sum under the first one's name.
    historyPartnerConversion($mine, 'Default', ['currency_amount' => 30]);
    historyPartnerConversion($mine, 'Rehearsal Reward', ['is_test' => true]);
    historyPartnerConversion($theirs, 'Their Reward');
    historyPartnerConversion($elsewhere, 'Other App Reward');

    $response = historyGet($app)->assertOk()
        ->assertSee('micro_reward')
        ->assertSee('Default')
        ->assertSee('MyChips')
        ->assertSee('+50')
        ->assertSee('+30')
        ->assertSee(now()->format('Y-m-d'))
        // The conversion's own time. The embed row's `created_at` never moves,
        // and reading the date off it would date every partner row to the day
        // the user first opened the frame.
        ->assertDontSee(now()->subMonths(2)->format('Y-m-d'))
        ->assertDontSee('Rehearsal Reward')
        ->assertDontSee('Their Reward')
        ->assertDontSee('Other App Reward');

    expect(substr_count($response->getContent(), 'wall-history__row'))->toBe(2);
});
