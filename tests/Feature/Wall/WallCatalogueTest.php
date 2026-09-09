<?php

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

it('shows pinned offers in a featured rail and marks them in the list', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();

    wallOffer($network, ['name' => 'Only Pinned', 'is_featured' => true, 'countries' => [], 'devices' => []]);
    wallOffer($network, ['name' => 'Not Pinned', 'countries' => [], 'devices' => []]);

    Cache::flush();

    $html = (string) wallGet($app)->assertOk()->getContent();

    // The rail is a second rendering of the same pin, not a replacement for
    // the list mark. A filter that hides the rail card still leaves the lead
    // edge on the row below, so the list stays honest about what is pinned.
    expect($html)
        ->toContain('wall-rail')
        ->toContain('wall-card--rail')
        ->toContain('wall-card--lead')
        ->toContain(__('wall.featured_offers'))
        ->toContain('wall-catalogue__title');

    expect(substr_count($html, 'wall-card--lead'))->toBe(1)
        ->and(substr_count($html, 'wall-card--rail'))->toBe(1)
        ->and(substr_count($html, 'Only Pinned'))->toBe(2);
});

it('omits the featured rail when nothing is pinned', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();

    wallOffer($network, ['name' => 'Not Pinned', 'countries' => [], 'devices' => []]);

    Cache::flush();

    $html = (string) wallGet($app)->assertOk()->getContent();

    expect($html)
        ->not->toContain('wall-rail')
        ->not->toContain('wall-card--rail')
        ->not->toContain('wall-card--lead');
});

it('always shows the history tab and hides offerwall when none are running', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    // The tab label is "Offerwall", which is also the start of the strip's
    // aria-label ("Offerwall sections"). Assert the destination, not the word.
    wallGet($app)->assertOk()
        ->assertSee(__('wall.tabs.history'))
        ->assertSee('/wall/history?appid='.$app->app_id, false)
        ->assertDontSee('/wall/partners?appid='.$app->app_id, false);
});

it('shows the offerwall tab when a partner wall is running', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);
    partnerNetwork();

    wallGet($app)->assertOk()
        ->assertSee(__('wall.tabs.partners'))
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

it('ships a device filter in the filter row', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => ['android']]);

    // Three toggles and no "all" button: the row already carries a chip with
    // that word, and pressing an active device turns it back off.
    wallGet($app)->assertOk()
        ->assertSee('wall-devices', false)
        ->assertSee(__('wall.filter.device'))
        ->assertSee('href="#ic-android"', false)
        ->assertSee('href="#ic-ios"', false)
        ->assertSee('href="#ic-desktop"', false);
});
