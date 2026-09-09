<?php

use App\Enums\NetworkIntegration;
use App\Filament\Admin\Resources\Networks\NetworkResource;
use App\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use App\Filament\Admin\Resources\Networks\Pages\ListNetworks;
use App\Models\Network;
use App\Models\User;
use App\Networks\Drivers\HangMyAdsDriver;
use App\Networks\Drivers\NotikDriver;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('saves a partner wall url and refuses one that is not https', function () {
    // `driver` stays a registered key: the field is disabled on edit, not
    // dehydrated(false), so Filament still validates it against the driver
    // manager's options on every save — a random factory slug fails there
    // regardless of what this test is actually exercising.
    $network = Network::factory()->create(['driver' => 'fake']);

    // `integration` comes first in every case: it is what puts the wall fields
    // on screen at all. See NetworkCapabilitiesTest for that contract.
    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['integration' => NetworkIntegration::Both->value, 'wall_url' => 'https://p.test/w?s={sub_id}', 'wall_new_tab' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($network->fresh()->wall_url)->toBe('https://p.test/w?s={sub_id}')
        ->and($network->fresh()->wall_new_tab)->toBeTrue();

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['integration' => NetworkIntegration::Both->value, 'wall_url' => 'http://p.test/w'])
        ->call('save')
        ->assertHasFormErrors(['wall_url']);
});

it('shows the hangmyads postback url with the macros the driver reads', function () {
    $network = Network::factory()->create(['driver' => 'hangmyads']);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->assertFormSet([
            'postback_url_display' => HangMyAdsDriver::postbackUrl(),
        ]);
});

it('copies the same postback url from the network list', function () {
    $network = Network::factory()->create(['driver' => 'hangmyads']);

    expect(NetworkResource::postbackUrl($network))->toBe(HangMyAdsDriver::postbackUrl());

    Livewire::test(ListNetworks::class)
        ->assertTableActionVisible('copy_postback', $network);
});

it('shows a bare postback url when the network sends a fixed payload', function () {
    $network = Network::factory()->create(['driver' => 'notik']);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->assertFormSet([
            'postback_url_display' => NotikDriver::postbackUrl(),
        ]);
});
