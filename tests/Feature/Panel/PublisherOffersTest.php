<?php

use App\Enums\AppStatus;
use App\Enums\BlacklistType;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Enums\PublisherStatus;
use App\Filament\Publisher\Pages\Offers;
use App\Filament\Publisher\Support\EligibleOffers;
use App\Models\App;
use App\Models\Blacklist;
use App\Models\Conversion;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Money\Money;
use Database\Factories\OfferClickFactory;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The offers inventory rules from docs/08 §5.
 *
 * The margin assertion is the one that matters commercially: the publisher sees
 * their own payout and never what the network paid us.
 */
function inventoryFixture(array $offer = []): array
{
    $publisher = Publisher::factory()->create(['rev_share' => 70.00]);
    $app = App::factory()->create(['publisher_id' => $publisher->id, 'status' => AppStatus::Active]);
    $network = Network::factory()->create(['status' => NetworkStatus::Active]);

    $created = Offer::factory()->create([
        'network_id' => $network->id,
        'status' => OfferStatus::Active,
        'payout' => '1.000000',
        ...$offer,
    ]);

    test()->actingAs($publisher->user);

    return [$publisher, $app, $created];
}

it('lists offers that are active on an active network', function () {
    [, $app, $offer] = inventoryFixture();

    expect(EligibleOffers::query($app->id)->pluck('id')->all())->toBe([$offer->id]);
});

it('hides offers on a disabled network', function () {
    [, $app, $offer] = inventoryFixture();

    $offer->network->update(['status' => NetworkStatus::Disabled]);

    expect(EligibleOffers::query($app->id)->count())->toBe(0);

    $this->get('/dashboard/offers')
        ->assertSuccessful()
        ->assertDontSee($offer->name);
});

it('hides offers blocked globally by an admin', function () {
    [, $app, $offer] = inventoryFixture();

    $offer->update(['status' => OfferStatus::Blocked, 'blocked_reason' => 'fraud']);

    expect(EligibleOffers::query($app->id)->count())->toBe(0);
});

it('shows the publisher payout after rev share, never the network payout', function () {
    [$publisher, , $offer] = inventoryFixture(['payout' => '1.000000']);

    $theirs = Money::share($offer->payout, Money::format($publisher->rev_share, 2));

    // 70% of $1.00. If this ever equals the offer payout, the page is leaking
    // our margin.
    expect($theirs)->toBe('0.700000')
        ->and($theirs)->not->toBe(Money::format($offer->payout));
});

it('removes a blocked offer from that app only', function () {
    [$publisher, $app, $offer] = inventoryFixture();

    $other = App::factory()->create(['publisher_id' => $publisher->id, 'status' => AppStatus::Active]);

    Blacklist::create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'type' => BlacklistType::Offer,
        'value' => (string) $offer->id,
    ]);

    expect(EligibleOffers::query($app->id)->count())->toBe(0)
        ->and(EligibleOffers::query($other->id)->count())->toBe(1);
});

it('applies an all-apps block everywhere', function () {
    [$publisher, $app, $offer] = inventoryFixture();

    $other = App::factory()->create(['publisher_id' => $publisher->id, 'status' => AppStatus::Active]);

    Blacklist::create([
        'publisher_id' => $publisher->id,
        'app_id' => null,
        'type' => BlacklistType::Offer,
        'value' => (string) $offer->id,
    ]);

    expect(EligibleOffers::query($app->id)->count())->toBe(0)
        ->and(EligibleOffers::query($other->id)->count())->toBe(0);
});

it('does not apply one publisher\'s blacklist to another', function () {
    [, $app, $offer] = inventoryFixture();

    $stranger = Publisher::factory()->create();
    $strangersApp = App::factory()->create(['publisher_id' => $stranger->id, 'status' => AppStatus::Active]);

    Blacklist::create([
        'publisher_id' => $stranger->id,
        'app_id' => null,
        'type' => BlacklistType::Offer,
        'value' => (string) $offer->id,
    ]);

    expect(EligibleOffers::query($app->id)->count())->toBe(1);

    test()->actingAs($stranger->user);

    expect(EligibleOffers::query($strangersApp->id)->count())->toBe(0);
});

it('drops a network-blocked offer', function () {
    [$publisher, $app, $offer] = inventoryFixture();

    Blacklist::create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'type' => BlacklistType::Network,
        'value' => (string) $offer->network_id,
    ]);

    expect(EligibleOffers::query($app->id)->count())->toBe(0);
});

it('reports the impact of a block before it is saved', function () {
    [, $app, $offer] = inventoryFixture();

    $impact = EligibleOffers::impact($app->id, BlacklistType::Offer->value, (string) $offer->id);

    expect($impact)->toContain('1')->toContain('100.0%');

    expect(EligibleOffers::impact($app->id, BlacklistType::Offer->value, '999999'))
        ->toContain('Nothing currently eligible');
});

it('never names the network an offer came from', function () {
    [, , $offer] = inventoryFixture();

    // Which network supplies an offer is ours. A publisher who can read it can
    // go direct, and the name is the whole of what they need to do it.
    $this->get('/dashboard/offers')
        ->assertSuccessful()
        ->assertSee($offer->name)
        ->assertDontSee($offer->network->name);
});

it('shows the publisher their share of the payout', function () {
    [, , $offer] = inventoryFixture(['payout' => '3.500000']);

    // 70% of $3.50 — and never the $3.50 itself.
    $this->get('/dashboard/offers')
        ->assertSuccessful()
        ->assertSee('$2.45')
        ->assertDontSee('$3.50');
});

/**
 * The sorts docs/08 §5 promises. Both are computed columns — the reward is
 * derived from the payout, and CR is a ratio of two `withCount` aliases — so
 * neither can be sorted by the column it displays, and the SQL standing in for
 * them is worth running at least once.
 */
it('sorts by user reward and by CR', function () {
    [$publisher, $app, $rich] = inventoryFixture(['payout' => '5.000000', 'name' => 'Rich offer']);

    $poor = Offer::factory()->create([
        'network_id' => $rich->network_id,
        'status' => OfferStatus::Active,
        'payout' => '0.100000',
        'name' => 'Poor offer',
    ]);

    // One click each: the rich offer converts nobody, the poor one converts
    // its only click. Sorted by reward they come out one way and by CR the
    // other, so an order that looks right by accident cannot pass both.
    foreach ([$rich, $poor] as $offer) {
        $click = OfferClickFactory::new()->create([
            'app_id' => $app->id,
            'publisher_id' => $publisher->id,
            'offer_id' => $offer->id,
            'network_id' => $offer->network_id,
        ]);

        if ($offer->is($poor)) {
            Conversion::factory()->create([
                'app_id' => $app->id,
                'publisher_id' => $publisher->id,
                'offer_id' => $offer->id,
                'offer_click_id' => $click->id,
                'click_id' => $click->click_id,
            ]);
        }
    }

    Filament::setCurrentPanel('publisher');

    Livewire::test(Offers::class, ['appId' => $app->id])
        ->sortTable('user_reward', 'desc')
        ->assertCanSeeTableRecords([$rich, $poor], inOrder: true)
        ->sortTable('cr_7d', 'desc')
        ->assertCanSeeTableRecords([$poor, $rich], inOrder: true);
});

it('refuses to invent a payout when the session has no publisher', function () {
    [$publisher] = inventoryFixture();

    // An admin browsing the publisher panel has no share to apply. Rendering
    // $0.0000 reads as "this offer pays nothing", which is a different and
    // wrong claim — the number is unknown here, so no number is shown.
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/dashboard/offers')
        ->assertSuccessful()
        ->assertDontSee('$0.0000');

    expect($publisher->rev_share)->toBe('70.00');
});

it('hides the catalogue from a pending publisher even with an active app', function () {
    [$publisher, $app, $offer] = inventoryFixture();
    $publisher->update([
        'status' => PublisherStatus::Pending,
        'approved_at' => null,
    ]);

    expect(EligibleOffers::catalogueOpen($app->id))->toBeFalse()
        ->and(EligibleOffers::query($app->id)->count())->toBe(0)
        ->and(EligibleOffers::impact($app->id, BlacklistType::Offer->value, (string) $offer->id))
        ->toContain('No eligible offers to measure against yet');
});

it('hides the catalogue for a non-active app', function (AppStatus $status) {
    [$publisher, $app] = inventoryFixture();
    $app->update(['status' => $status]);

    expect(EligibleOffers::catalogueOpen($app->id))->toBeFalse()
        ->and(EligibleOffers::query($app->id)->count())->toBe(0);
})->with([
    AppStatus::Pending,
    AppStatus::Paused,
    AppStatus::Rejected,
]);

it('hides the all-apps catalogue when the publisher has no active app', function () {
    [$publisher, $app] = inventoryFixture();
    $app->update(['status' => AppStatus::Pending]);

    expect(EligibleOffers::catalogueOpen(null))->toBeFalse()
        ->and(EligibleOffers::query(null)->count())->toBe(0);
});

it('keeps the catalogue open for an approved publisher and an active app', function () {
    [, $app, $offer] = inventoryFixture();

    expect(EligibleOffers::catalogueOpen($app->id))->toBeTrue()
        ->and(EligibleOffers::catalogueOpen(null))->toBeTrue()
        ->and(EligibleOffers::query($app->id)->pluck('id')->all())->toBe([$offer->id]);
});

it('does not default the offers page to a pending app', function () {
    $publisher = Publisher::factory()->create();
    App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Pending,
        'name' => 'Still pending',
    ]);
    $network = Network::factory()->create(['status' => NetworkStatus::Active]);
    Offer::factory()->create([
        'network_id' => $network->id,
        'status' => OfferStatus::Active,
        'name' => 'Secret catalogue',
    ]);

    test()->actingAs($publisher->user);
    Filament::setCurrentPanel('publisher');

    Livewire::test(Offers::class)
        ->assertSet('appId', null)
        ->assertSee('<option value="" disabled selected>Waiting for approval</option>', false)
        ->assertSee('Waiting for approval')
        ->assertSee('Offers appear after your account and an app are approved.')
        ->assertDontSee('Secret catalogue');
});

it('defaults the offers page to the first active app', function () {
    [$publisher, $app, $offer] = inventoryFixture(['name' => 'Live offer']);
    App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Pending,
    ]);

    Filament::setCurrentPanel('publisher');

    Livewire::test(Offers::class)
        ->assertSet('appId', $app->id)
        ->assertSee('Live offer')
        ->assertDontSee('Waiting for approval');
});

it('shows the waiting empty state for a pending publisher on an active app', function () {
    [$publisher, $app, $offer] = inventoryFixture(['name' => 'Live offer']);
    $publisher->update([
        'status' => PublisherStatus::Pending,
        'approved_at' => null,
    ]);

    Filament::setCurrentPanel('publisher');

    Livewire::test(Offers::class, ['appId' => $app->id])
        ->assertSee('Waiting for approval')
        ->assertSee('Offers appear after your account and this app are approved.')
        ->assertDontSee('Live offer');
});
