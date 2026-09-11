<?php

use App\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use App\Filament\Admin\Resources\Networks\Pages\ListNetworks;
use App\Models\Conversion;
use App\Models\Network;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('does not offer delete on the list or the edit page', function () {
    $network = Network::factory()->create(['driver' => 'ogads']);

    Livewire::test(ListNetworks::class)
        ->assertTableActionDoesNotExist('delete');

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

it('refuses to delete a network that has conversions', function () {
    $network = Network::factory()->create(['driver' => 'ogads']);
    Conversion::factory()->create([
        'offer_id' => Offer::factory()->create(['network_id' => $network->id]),
        'network_id' => $network->id,
    ]);

    expect($network->canBeDeleted())->toBeFalse()
        ->and(fn () => $network->delete())
        ->toThrow(LogicException::class, 'A network with conversions cannot be deleted.')
        ->and(Network::query()->whereKey($network->id)->exists())->toBeTrue();
});
