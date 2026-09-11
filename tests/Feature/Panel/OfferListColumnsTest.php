<?php

use App\Enums\AppStatus;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Enums\PublisherStatus;
use App\Models\App;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * Geo and device are JSON list columns rendered as stacked flags and glyphs.
 *
 * They were `formatStateUsing` closures written for the whole list, and Filament
 * hands such a closure one array item at a time — so every row silently
 * rendered the "empty" branch and read as worldwide, on every device, in both
 * panels. These assert on the markup a flag actually needs.
 */
function offerWithTargeting(array $attributes): Offer
{
    $network = Network::factory()->create(['status' => NetworkStatus::Active]);

    return Offer::factory()->create([
        'network_id' => $network->id,
        'status' => OfferStatus::Active,
        ...$attributes,
    ]);
}

it('lists the real geo and device targeting on the admin offers table', function () {
    Config::set('playndgain.require_admin_2fa', false);

    offerWithTargeting([
        'name' => 'Targeted offer',
        'countries' => ['PT', 'SE', 'NZ', 'HR', 'DE'],
        'devices' => ['android'],
    ]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $this->get('/admin/offers')
        ->assertSuccessful()
        ->assertSee('flags/pt.svg', escape: false)
        ->assertSee('flags/se.svg', escape: false)
        ->assertSee('flags/nz.svg', escape: false)
        ->assertSee('width="22"', escape: false)
        // Three shown, the rest counted — the whole list is the tooltip's job,
        // and it names countries rather than repeating the codes.
        ->assertDontSee('flags/hr.svg', escape: false)
        ->assertSee('+2')
        ->assertSee('Portugal, Sweden, New Zealand, Croatia, Germany')
        ->assertSee('img/devices/android.svg', escape: false);
});

it('calls an untargeted offer worldwide on the admin offers table', function () {
    Config::set('playndgain.require_admin_2fa', false);

    offerWithTargeting(['name' => 'Worldwide offer', 'countries' => [], 'devices' => []]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $this->get('/admin/offers')->assertSuccessful()->assertSee('all');
});

it('hides offers whose network is no longer active', function () {
    Config::set('playndgain.require_admin_2fa', false);

    $live = offerWithTargeting(['name' => 'Live network offer']);
    $disabled = offerWithTargeting(['name' => 'Disabled network offer']);
    $errored = offerWithTargeting(['name' => 'Errored network offer']);

    $disabled->network->update(['status' => NetworkStatus::Disabled]);
    $errored->network->update(['status' => NetworkStatus::Error]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $this->get('/admin/offers')
        ->assertSuccessful()
        ->assertSee($live->name)
        ->assertDontSee($disabled->name)
        ->assertDontSee($errored->name);
});

it('lists the real geo and device targeting on the publisher offers page', function () {
    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Approved]);
    App::factory()->create(['publisher_id' => $publisher->id, 'status' => AppStatus::Active]);

    offerWithTargeting([
        'name' => 'Targeted offer',
        'countries' => ['PT', 'SE', 'NZ', 'HR', 'DE'],
        'devices' => ['ios'],
    ]);

    $this->actingAs($publisher->user);

    $this->get('/dashboard/offers')
        ->assertSuccessful()
        ->assertSee('flags/pt.svg', escape: false)
        ->assertSee('flags/se.svg', escape: false)
        ->assertSee('Portugal, Sweden, New Zealand, Croatia, Germany')
        ->assertSee('img/devices/ios.svg', escape: false);
});

it('keeps flag styling out of Filament class namespace', function () {
    // flag-icons names a class after every ISO code, and Filament prefixes its
    // own classes `fi-` — `.fi-no` is the notifications overlay, `.fi-ta` the
    // table wrapper. Importing that stylesheet painted the Norwegian flag
    // across the entire viewport. The SVGs are used directly instead.
    expect(file_get_contents(resource_path('css/filament/theme.css')))
        ->not->toContain("@import 'flag-icons")
        ->not->toContain('@import "flag-icons')
        // EasyList generic-hides the ad-prefixed flag class. The class has
        // to stay off that name or every ad blocker blanks the geo column.
        ->not->toContain('.ad-flag')
        ->not->toContain('ad-flag');
});
