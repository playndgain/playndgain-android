<?php

use App\Enums\ConversionStatus;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Filament\Admin\Resources\Offers\OfferResource;
use App\Filament\Admin\Resources\Offers\Pages\ListOffers;
use App\Models\App;
use App\Models\Conversion;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Models\User;
use App\Providers\NetworkServiceProvider;
use Database\Factories\OfferClickFactory;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Offers we sell directly, typed in by an admin.
 *
 * They hang off the seeded `direct` network so that nothing downstream is
 * special-cased: the click, the postback, the fraud pass and the ledger all run
 * the same code a network's offer runs.
 */
beforeEach(function () {
    Config::set('advault.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->app->register(NetworkServiceProvider::class);

    $this->direct = Network::factory()->create([
        'driver' => 'direct',
        'name' => 'AdVault (house)',
        'status' => NetworkStatus::Active,
        'credentials' => ['postback_secret' => 'direct-secret'],
        'postback_ips' => ['127.0.0.1'],
    ]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('describes every field on the add-offer form', function () {
    $page = Livewire::test(ListOffers::class);
    $fields = OfferResource::form(Schema::make($page->instance()))->getFlatFields(withHidden: true);

    expect($fields)->toHaveKeys([
        'name', 'tracking_url', 'payout', 'conversion_type', 'description', 'instructions',
        'countries', 'devices', 'categories', 'os_min_version', 'icon_url', 'daily_cap',
        'status', 'is_featured', 'is_multi_reward',
    ]);

    expect($fields['name']->getPlaceholder())->toBe('Cases.gg')
        ->and($fields['tracking_url']->getPlaceholder())->toContain('{click_id}')
        ->and($fields['payout']->getPlaceholder())->toBe('4.500000')
        ->and($fields['conversion_type']->getPlaceholder())->toBe('What the user does')
        ->and($fields['description']->getPlaceholder())->toContain('village builder')
        ->and($fields['instructions']->getPlaceholder())->toContain('Reach village 5')
        ->and($fields['countries']->getPlaceholder())->toBe('US')
        ->and($fields['devices']->getPlaceholder())->toBe('Every device')
        ->and($fields['categories']->getPlaceholder())->toBe('games')
        ->and($fields['os_min_version']->getPlaceholder())->toBe('14.0')
        ->and($fields['icon_url']->getPlaceholder())->toContain('icon.png')
        ->and($fields['daily_cap']->getPlaceholder())->toBe('500');

    $source = file_get_contents(app_path('Filament/Admin/Resources/Offers/OfferResource.php'));

    expect($source)
        ->toContain('advertiser pays us')
        ->toContain('echo it on the postback')
        ->toContain('ISO-2 country codes')
        ->toContain('Pins this offer to the top')
        ->toContain('more than once');
});

it('adds an offer by hand and files it under the direct network', function () {
    Livewire::test(ListOffers::class)
        ->callAction('create', data: [
            'name' => 'Sponsored newsletter signup',
            'tracking_url' => 'https://advertiser.example/lp?c={click_id}',
            'payout' => '4.5',
            'conversion_type' => 'cpl',
            'countries' => ['us', 'ca'],
            'devices' => ['desktop'],
            'status' => OfferStatus::Active->value,
        ])
        ->assertHasNoActionErrors();

    $offer = Offer::query()->sole();

    expect($offer->network_id)->toBe($this->direct->id)
        ->and($offer->name)->toBe('Sponsored newsletter signup')
        ->and((float) $offer->payout)->toBe(4.5)
        // Uppercased on the way in, because that is what the wall filters on.
        ->and($offer->countries)->toBe(['US', 'CA'])
        ->and($offer->devices)->toBe(['desktop'])
        // Generated: the advertiser has no id of their own to give us, and the
        // column is unique with network_id.
        ->and($offer->external_id)->toStartWith('direct-')
        // The table sorts on last_seen_at — a null buries a new offer under
        // every synced row.
        ->and($offer->last_seen_at)->not->toBeNull()
        ->and($offer->first_seen_at)->not->toBeNull();
});

it('refuses a tracking url with nowhere to put the click id', function () {
    // An untracked click is an unpaid conversion, so this is rejected at the
    // form rather than silently patched.
    Livewire::test(ListOffers::class)
        ->callAction('create', data: [
            'name' => 'Broken link',
            'tracking_url' => 'https://advertiser.example/lp',
            'payout' => '1',
            'conversion_type' => 'cpl',
            'status' => OfferStatus::Active->value,
        ])
        ->assertHasActionErrors(['tracking_url']);

    expect(Offer::query()->count())->toBe(0);
});

it('credits a conversion the advertiser posts back', function () {
    Queue::fake();

    $publisher = Publisher::factory()->create(['rev_share' => 70.00]);
    $placement = App::factory()->create(['publisher_id' => $publisher->id]);

    $offer = Offer::factory()->create([
        'network_id' => $this->direct->id,
        'external_id' => 'direct-abc123',
        'status' => OfferStatus::Active,
        'tracking_url' => 'https://advertiser.example/lp?c={click_id}',
    ]);

    $click = OfferClickFactory::new()->create([
        'app_id' => $placement->id,
        'publisher_id' => $publisher->id,
        'offer_id' => $offer->id,
        'network_id' => $this->direct->id,
    ]);

    $this->post('/postback/direct', [
        'transaction_id' => 'ADV-5501',
        'click_id' => $click->click_id,
        'payout' => '4.50',
        'secret' => 'direct-secret',
    ])->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->network_id)->toBe($this->direct->id)
        ->and($conversion->offer_id)->toBe($offer->id)
        ->and($conversion->network_txid)->toBe('ADV-5501')
        ->and((float) $conversion->revenue)->toBe(4.5)
        // 70% of what the advertiser paid us.
        ->and((float) $conversion->payout)->toBe(3.15)
        ->and($conversion->status)->toBeIn([ConversionStatus::Approved, ConversionStatus::Pending]);
});

it('rejects a postback that does not carry the secret', function () {
    $this->post('/postback/direct', [
        'transaction_id' => 'ADV-5502',
        'click_id' => 'whatever',
        'payout' => '4.50',
    ])->assertForbidden();

    expect(Conversion::query()->count())->toBe(0);
});

it('refuses a country tag that is not an ISO-2 code', function () {
    // Two reasons, and the smaller one is the obvious one. A code that is not
    // two letters can never match the country the wall resolves, so a typo here
    // is an offer nobody is ever shown and no error anybody sees. The larger
    // one: the same column is rendered into an attribute and a filename on the
    // wall, so malformed and hostile are one class of input.
    Livewire::test(ListOffers::class)
        ->callAction('create', data: [
            'name' => 'Bad geo',
            'tracking_url' => 'https://advertiser.example/lp?s1={click_id}',
            'payout' => '1',
            'conversion_type' => 'cpl',
            'status' => OfferStatus::Active->value,
            'countries' => ['US', "a');alert(1)//"],
        ])
        // Indexed, not `countries`: the rule is per element, so the failure is
        // reported against the element that failed.
        ->assertHasActionErrors(['countries.1']);

    expect(Offer::query()->count())->toBe(0);
});

it('accepts a well-formed country tag', function () {
    Livewire::test(ListOffers::class)
        ->callAction('create', data: [
            'name' => 'Good geo',
            'tracking_url' => 'https://advertiser.example/lp?s1={click_id}',
            'payout' => '1',
            'conversion_type' => 'cpl',
            'status' => OfferStatus::Active->value,
            'countries' => ['us', 'GB'],
        ])
        ->assertHasNoActionErrors();

    expect(Offer::query()->sole()->countries)->toBe(['US', 'GB']);
});
