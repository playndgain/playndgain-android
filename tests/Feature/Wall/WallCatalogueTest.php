<?php

use App\Models\Network;
use App\Models\Offer;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\WallContext;
use App\Services\Wall\WallGate;
use Database\Factories\OfferClickFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/WallFixtures.php';

/** A real WallContext, for the assertions that read the feed rather than a page. */
function catalogueContext(App\Models\App $app): WallContext
{
    $request = Request::create('/wall', 'GET', ['appid' => $app->app_id, 'userid' => 'USER_1']);
    $request->headers->set('User-Agent', WALL_UA);

    return app(WallGate::class)->context($request, $app);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function popularityClick(Offer $offer, string $user, array $attributes = []): void
{
    OfferClickFactory::new()->create([
        'offer_id' => $offer->id,
        'network_id' => $offer->network_id,
        'end_user_id' => $user,
        ...$attributes,
    ]);
}

it('counts people rather than clicks, and only the ones that count', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();

    $busy = wallOffer($network, ['name' => 'Busy Offer', 'countries' => [], 'devices' => []]);
    $quiet = wallOffer($network, ['name' => 'Quiet Offer', 'countries' => [], 'devices' => []]);

    // One person, three opens. The glyph is a person, and raw rows are a number
    // a bored user can inflate by themselves.
    popularityClick($busy, 'USER_A');
    popularityClick($busy, 'USER_A');
    popularityClick($busy, 'USER_A');
    popularityClick($busy, 'USER_B');

    // A fraud refusal is not social proof; the publisher's own integration
    // testing is not a user; and a lifetime count freezes the wall.
    popularityClick($quiet, 'USER_C', ['blocked' => true]);
    popularityClick($quiet, 'USER_D', ['is_test' => true]);
    popularityClick($quiet, 'USER_E', ['created_at' => now()->subDays(31)]);

    expect(app(OfferFeed::class)->popularity([$busy->id, $quiet->id]))
        ->toBe([$busy->id => 2]);

    // Zero is omitted entirely — "0 people" is worse than silence on a card the
    // wall wants clicked.
    wallGet($app)->assertOk()
        ->assertSee(trans_choice('wall.people', 2))
        ->assertSee('>+2<', false)
        // Empty targeting is any device — the three glyphs, not a lone count.
        ->assertSee('#ic-android', false)
        ->assertSee('#ic-ios', false)
        ->assertSee('#ic-desktop', false);
});

it('counts an offer across every publisher, not just this one', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    [$other] = wallFixture(driver: $network->driver);

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    // A per-app count reads "0" on every card of a new app's wall — the exact
    // moment the number is least helpful.
    popularityClick($offer, 'USER_A', ['app_id' => $other->id, 'publisher_id' => $other->publisher_id]);

    expect(app(OfferFeed::class)->popularity([$offer->id]))->toBe([$offer->id => 1]);
});

it('carries the popularity count on the cached payload rather than per render', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);
    popularityClick($offer, 'USER_A');

    wallGet($app)->assertOk();

    DB::enableQueryLog();
    wallGet($app)->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'offer_clicks')))->toBeEmpty();
});

it('reorders on ?sort= and falls back to the default on anything else', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();

    $featured = wallOffer($network, ['name' => 'Cheap Featured', 'payout' => 0.10, 'is_featured' => true, 'countries' => [], 'devices' => []]);
    $rich = wallOffer($network, ['name' => 'Rich Plain', 'payout' => 9.00, 'countries' => [], 'devices' => []]);
    $popular = wallOffer($network, ['name' => 'Popular Middle', 'payout' => 1.00, 'countries' => [], 'devices' => []]);

    popularityClick($popular, 'USER_A');
    popularityClick($popular, 'USER_B');
    popularityClick($rich, 'USER_A');

    $context = catalogueContext($app);

    $order = fn (?string $sort): array => collect(app(OfferFeed::class)->for($context, $sort))->pluck('name')->all();

    expect($order(null))->toBe([$featured->name, $rich->name, $popular->name])
        ->and($order('reward'))->toBe([$rich->name, $popular->name, $featured->name])
        // Payout is the tiebreak, so the two offers nobody touched keep their
        // own order beneath the one people did.
        ->and($order('trending'))->toBe([$popular->name, $rich->name, $featured->name])
        // The parameter is in a URL the end user can edit; a typo is a wall,
        // not a 500.
        ->and($order('drop table'))->toBe($order(null));

    wallGet($app, extra: ['sort' => 'nonsense'])->assertOk()->assertSee($featured->name);
});

it('shows the featured rail only once there are two offers on it', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();

    wallOffer($network, ['name' => 'Only Pinned', 'is_featured' => true, 'countries' => [], 'devices' => []]);

    // A rail of one is a card with a scrollbar.
    wallGet($app)->assertOk()->assertDontSee('wall-rail__track', false);

    wallOffer($network, ['name' => 'Second Pinned', 'is_featured' => true, 'countries' => [], 'devices' => []]);

    // New inventory does not invalidate the payload by itself — a sync job or
    // an admin save flushes it by tag (App\Networks\OfferCacheFlusher), and
    // this stands in for that.
    Cache::flush();

    wallGet($app)->assertOk()->assertSee('wall-rail__track', false);
});

it('always shows the history and offerwall tabs', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    wallGet($app)->assertOk()
        ->assertSee(__('wall.tabs.history'))
        ->assertSee(__('wall.tabs.partners'))
        ->assertSee('/wall/history?appid='.$app->app_id, false)
        ->assertSee('/wall/partners?appid='.$app->app_id, false);
});

it('ships the offer sheet chrome and a loading skeleton', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    wallGet($app)->assertOk()
        ->assertSee('offer-dialog__chrome', false)
        ->assertSee('offer-dialog-skeleton', false)
        ->assertSee('offer-skeleton', false)
        ->assertSee(__('wall.offer.loading'));
});

it('ships a device filter next to search', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => ['android']]);

    wallGet($app)->assertOk()
        ->assertSee('wall-devices', false)
        ->assertSee(__('wall.filter.device'))
        ->assertSee(__('wall.filter.all_devices'))
        ->assertSee('href="#ic-android"', false)
        ->assertSee('href="#ic-ios"', false)
        ->assertSee('href="#ic-desktop"', false);
});
