<?php

use App\Enums\AppStatus;
use App\Enums\ConversionStatus;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Enums\OutgoingPostbackKind;
use App\Enums\PublisherStatus;
use App\Filament\Publisher\Pages\Statistics;
use App\Filament\Publisher\Resources\PostbackReports\Pages\ListPostbackReports;
use App\Filament\Publisher\Resources\Transactions\Pages\ListTransactions;
use App\Models\App;
use App\Models\Conversion;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Services\Money\MacroResolver;
use Database\Factories\OfferClickFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * AGENTS.md's first rule, as a test.
 *
 * A publisher who can name the network behind an offer can go and buy it
 * directly, which is the one failure mode that ends the business rather than
 * costing it money. The platform connectors make that rule harder to keep than
 * it was: a Swaarm or Affise account carries a *tenant* identity — a hostname
 * with the partner's name in it, a per-row callback key, an account scope —
 * on top of the driver key every direct network already had.
 *
 * Every string below is one an admin typed or the system minted, and none of
 * them may reach a publisher.
 */
require_once __DIR__.'/../Money/MoneyFixtures.php';

/**
 * A whole conversion, sourced from a platform account whose every field is
 * shaped like the leak we are looking for.
 *
 * @return array{0: Publisher, 1: App, 2: Offer, 3: Conversion, 4: Network}
 */
function anonymityFixture(): array
{
    $network = Network::factory()->platform('swaarm')->create([
        'name' => 'Acme Media (Swaarm)',
        'status' => NetworkStatus::Active,
        'credentials' => [
            'feed_url' => 'https://feed.acme.swaarm-clients.com/feed/v1.2/ads',
            'api_key' => 'acme-feed-key',
            'postback_secret' => 'acme-postback-secret',
        ],
    ]);

    $publisher = Publisher::factory()->create([
        'rev_share' => 70.00,
        'status' => PublisherStatus::Approved,
    ]);

    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Active,
        'postback_url' => 'https://pub.test/cb?tx={trans_id}&offer={offer_id}&user={user_id}',
    ]);

    $offer = Offer::factory()->create([
        'network_id' => $network->id,
        'status' => OfferStatus::Active,
        'name' => 'Coin Quest',
        // A payout-variant id, as `ExpandsPayoutVariants` mints them.
        'external_id' => '1001~2',
        'tracking_url' => 'https://track.acme.swaarm-clients.com/click?ad=1001&pub=42',
        'payout' => '1.000000',
        'countries' => [],
        'devices' => [],
    ]);

    $click = OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
    ]);

    $conversion = Conversion::factory()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
        'offer_click_id' => $click->id,
        'offer_name' => $offer->name,
        'status' => ConversionStatus::Approved,
        // The audit column Phase 2 added. It is never publisher-facing, and
        // this fixture is the only thing that would prove it if it were.
        'upstream_status' => ConversionStatus::Rejected,
        'upstream_updated_at' => now(),
    ]);

    test()->actingAs($publisher->user);

    return [$publisher, $app, $offer, $conversion, $network];
}

/**
 * Everything that identifies the upstream account, in one list.
 *
 * @return array<int, string>
 */
function anonymityNeedles(Network $network): array
{
    return array_values(array_filter([
        // The connector keys themselves.
        'swaarm',
        'affise',
        // Per-row callback identity, minted by the model.
        $network->postback_key,
        $network->account_scope,
        // The audit column, and the tenant's own hostname.
        'upstream_status',
        'swaarm-clients.com',
        'feed.acme',
        'track.acme',
        // What an admin typed, and what the connector calls itself.
        $network->name,
        'Acme Media',
        (string) $network->connector()?->label,
    ]));
}

/**
 * Case-insensitive because a driver key can reach HTML title-cased, and a
 * hostname can arrive percent-encoded inside an href.
 */
function assertAnonymous(string $haystack, Network $network, string $surface): void
{
    $flat = strtolower($haystack.' '.rawurldecode($haystack));

    $found = array_values(array_filter(
        anonymityNeedles($network),
        fn (string $needle): bool => str_contains($flat, strtolower($needle)),
    ));

    // Keyed by the surface so a failure names where it leaked, not just what.
    expect([$surface => $found])->toBe([$surface => []]);
}

it('never names the upstream account on the publisher offers page', function () {
    [, $app, $offer, , $network] = anonymityFixture();

    $html = $this->get('/dashboard/offers?app='.$app->id)->assertSuccessful()->getContent();

    // The offer is on screen; only its source is not.
    expect($html)->toContain($offer->name);

    // Every `href` is stripped first, because of one of them: the `preview`
    // row action is documented (docs/08 §5) as opening the advertiser's own
    // landing page, so it points at the upstream tracking URL by design and
    // the publisher reads that host out of their address bar either way. That
    // one attribute is the only place the source may appear — the next test
    // pins it to exactly one occurrence — and everything else on the page has
    // to be anonymous with it gone.
    assertAnonymous(
        (string) preg_replace('/href="[^"]*"/', 'href="#"', $html),
        $network,
        'The publisher offers page',
    );
});

it('leaks the upstream host in the preview href and nowhere else', function () {
    [, $app] = anonymityFixture();

    $html = $this->get('/dashboard/offers?app='.$app->id)->assertSuccessful()->getContent();

    // Recorded rather than asserted away. It predates the platform connectors
    // — every direct network's tracking domain reaches this same href — and
    // whether the preview should route through a platform-owned redirect is an
    // owner's decision about a documented feature, not this suite's. What this
    // test does is make a *second* occurrence fail.
    expect(substr_count($html, 'href="https://track.acme.swaarm-clients.com'))->toBe(1)
        // And outside the hrefs, nothing at all.
        ->and(substr_count(strtolower((string) preg_replace('/href="[^"]*"/', '', $html)), 'swaarm'))->toBe(0);
});

it('never names the upstream account on the publisher transactions list or its view', function () {
    [, , , $conversion, $network] = anonymityFixture();
    Filament::setCurrentPanel('publisher');

    $list = Livewire::test(ListTransactions::class)
        ->assertCanSeeTableRecords([$conversion]);

    assertAnonymous($list->html(), $network, 'The transactions list');

    // The row's detail is a slide-over rather than a page, so it only renders
    // once the action is mounted.
    $view = $list->mountAction(TestAction::make('view')->table($conversion))->assertSuccessful();

    assertAnonymous($view->html(), $network, 'The transaction detail');
});

it('never names the upstream account on publisher statistics', function () {
    [, , , , $network] = anonymityFixture();
    Filament::setCurrentPanel('publisher');

    $html = $this->get('/dashboard/statistics')->assertSuccessful()->getContent();
    assertAnonymous($html, $network, 'The statistics page');

    assertAnonymous(Livewire::test(Statistics::class)->html(), $network, 'The statistics page');
});

it('never names the upstream account on the postback report or in the macros it resolves', function () {
    [, $app, , $conversion, $network] = anonymityFixture();

    // The URL the publisher is actually called back on, macros and all.
    $url = app(MacroResolver::class)->resolve((string) $app->postback_url, $conversion, OutgoingPostbackKind::Credit);

    // `{offer_id}` resolves to the *network's* external id, which on a platform
    // connector is a variant id minted from the tenant's own ad id. It carries
    // no host and no connector key, and this is the assertion that keeps it
    // that way.
    assertAnonymous($url, $network, 'The outgoing postback URL');

    Filament::setCurrentPanel('publisher');

    assertAnonymous(Livewire::test(ListPostbackReports::class)->html(), $network, 'The postback report');
});

it('has no export a publisher can trigger', function () {
    // CSV export lives in `app/Filament/Admin/Support/CsvExport.php` and is
    // wired into admin pages only. A publisher-side export would have to
    // choose its own columns, and choosing wrong is a leak rather than a bug.
    $publisherPanel = shell_exec(
        'grep -rlE "CsvExport|streamDownload|StreamedResponse" '.escapeshellarg(app_path('Filament/Publisher')).' 2>/dev/null'
    );

    expect(trim((string) $publisherPanel))->toBe('');
});

it('keeps every connector out of the public docs', function () {
    Cache::flush();

    $network = Network::factory()->platform('swaarm')->create(['name' => 'Acme Media (Swaarm)']);

    $llms = $this->get('/docs/llms.txt')->assertOk()->getContent();
    assertAnonymous($llms, $network, 'llms.txt');

    foreach (collect(config('docs.nav'))->pluck('pages')->flatten(1)->pluck('slug') as $slug) {
        assertAnonymous(
            $this->get("/docs/{$slug}.md")->assertOk()->getContent(),
            $network,
            "The published page {$slug}.md",
        );
    }
});
