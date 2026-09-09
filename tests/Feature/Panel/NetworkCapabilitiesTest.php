<?php

use App\Enums\NetworkIntegration;
use App\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use App\Filament\Admin\Resources\Networks\Pages\ListNetworks;
use App\Models\Network;
use App\Models\User;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\NetworkDriverManager;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

/**
 * A partner can bring an API catalogue, a wall of its own, or both — and the
 * admin screen should only ask about what it actually brings.
 *
 * Whether a driver *has* a catalogue is a property of the driver class.
 * Whether we *call* it is `networks.integration`: embed-only skips the
 * catalogue keys the schema otherwise requires, and skips the sync sweep.
 */
beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    app(NetworkDriverManager::class)->register('fake', FakeNetworkDriver::class);
});

it('reads the catalogue capability off the driver, not off a column', function () {
    // `fake` extends AbstractDriver, which syncs. `direct` is the hand-entered
    // channel and says so itself. Embed-only is the admin override on top.
    $api = Network::factory()->create(['driver' => 'fake']);

    expect($api->syncsCatalogue())->toBeTrue()
        ->and(Network::factory()->create(['driver' => 'direct'])->syncsCatalogue())->toBeFalse();

    $api->update(['integration' => NetworkIntegration::Embed]);

    expect($api->fresh()->syncsCatalogue())->toBeFalse();
});

it('treats a driver it cannot resolve as having no catalogue', function () {
    // A row whose driver class was removed must not blow up the admin table on
    // its way to telling an operator something is wrong.
    expect(Network::factory()->create(['driver' => 'nobody-registered-this'])->syncsCatalogue())->toBeFalse();
});

it('defaults a network to bringing no wall of its own', function () {
    expect(Network::factory()->create()->fresh()->hostsWall())->toBeFalse();
});

it('hides the sync fields for a partner whose driver has no catalogue', function () {
    $api = Network::factory()->create(['driver' => 'fake']);
    $embed = Network::factory()->create(['driver' => 'direct']);

    Livewire::test(EditNetwork::class, ['record' => $api->getRouteKey()])
        ->assertFormFieldExists('sync_interval_minutes');

    Livewire::test(EditNetwork::class, ['record' => $embed->getRouteKey()])
        ->assertFormFieldDoesNotExist('sync_interval_minutes');
});

it('reveals the wall fields only once a partner is marked as hosting one', function () {
    $network = Network::factory()->create(['driver' => 'fake', 'integration' => NetworkIntegration::Api]);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->assertFormFieldDoesNotExist('wall_url')
        ->assertFormFieldExists('integration')
        ->fillForm(['integration' => NetworkIntegration::Embed->value])
        ->assertFormFieldExists('wall_url');
});

it('refuses to mark a partner as hosting a wall without giving one', function () {
    $network = Network::factory()->create(['driver' => 'fake']);

    // Marked as hosting a wall with nothing to frame is a tab that 404s.
    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['integration' => NetworkIntegration::Both->value, 'wall_url' => ''])
        ->call('save')
        ->assertHasFormErrors(['wall_url']);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['integration' => NetworkIntegration::Both->value, 'wall_url' => 'https://p.test/w?s={sub_id}'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($network->fresh()->hostsWall())->toBeTrue()
        ->and($network->fresh()->integration)->toBe(NetworkIntegration::Both);
});

it('still requires catalogue credentials on an API integration', function () {
    $network = Network::factory()->create([
        'driver' => 'hangmyads',
        'credentials' => null,
    ]);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['name' => 'HangMyAds'])
        ->call('save')
        ->assertHasFormErrors(['credentials.api_id', 'credentials.api_token']);
});

it('saves an embedded wall without catalogue credentials', function () {
    $network = Network::factory()->create([
        'driver' => 'hangmyads',
        'credentials' => null,
        'integration' => NetworkIntegration::Api,
    ]);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm([
            'integration' => NetworkIntegration::Embed->value,
            'wall_url' => 'https://offerwall.hangmyads.com/ow?pubid=1&subid={sub_id}',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($network->fresh()->integration)->toBe(NetworkIntegration::Embed)
        ->and($network->fresh()->hostsWall())->toBeTrue()
        ->and($network->fresh()->credentials)->toBeEmpty()
        ->and($network->fresh()->syncsCatalogue())->toBeFalse();
});

it('drops the catalogue columns and actions for a partner that has no catalogue', function () {
    $network = Network::factory()->create(['driver' => 'direct', 'name' => 'Wall Only Co']);

    $html = $this->get('/admin/networks')->assertSuccessful()->getContent();

    expect($html)->toContain('Wall Only Co');

    Livewire::test(ListNetworks::class)
        ->assertSee('Wall Only Co')
        ->assertTableActionHidden('sync', $network)
        ->assertTableActionHidden('test', $network)
        ->assertTableActionHidden('offers', $network)
        ->assertTableActionVisible('toggle', $network)
        ->assertTableActionVisible('copy_postback', $network);
});

it('lists networks highest priority first', function () {
    $low = Network::factory()->create(['name' => 'Low Co', 'priority' => 1]);
    $high = Network::factory()->create(['name' => 'High Co', 'priority' => 9]);

    Livewire::test(ListNetworks::class)
        ->assertCanSeeTableRecords([$high, $low], inOrder: true);
});

it('writes a higher priority onto the network dragged to the top', function () {
    $low = Network::factory()->create(['name' => 'Low Co', 'priority' => 1]);
    $high = Network::factory()->create(['name' => 'High Co', 'priority' => 9]);

    Livewire::test(ListNetworks::class)
        ->call('reorderTable', [(string) $low->getKey(), (string) $high->getKey()]);

    expect($low->fresh()->priority)->toBeGreaterThan($high->fresh()->priority);
});

it('shows each integration type as an icon with a tooltip', function () {
    Network::factory()->create([
        'name' => 'Api Co',
        'integration' => NetworkIntegration::Api,
    ]);
    Network::factory()->create([
        'name' => 'Embed Co',
        'integration' => NetworkIntegration::Embed,
        'hosts_wall' => true,
        'wall_url' => 'https://p.test/w',
    ]);
    Network::factory()->create([
        'name' => 'Both Co',
        'integration' => NetworkIntegration::Both,
        'hosts_wall' => true,
        'wall_url' => 'https://p.test/w',
    ]);

    $html = $this->get('/admin/networks')->assertSuccessful()->getContent();

    expect($html)
        ->toContain(NetworkIntegration::Api->getDescription())
        ->toContain(NetworkIntegration::Embed->getDescription())
        ->toContain(NetworkIntegration::Both->getDescription());
});

it('labels each integration type for the admin table', function (NetworkIntegration $type, string $label) {
    expect($type->getLabel())->toBe($label)
        ->and($type->getIcon())->not->toBeNull()
        ->and($type->getDescription())->not->toBeEmpty();
})->with([
    [NetworkIntegration::Api, 'API catalogue only'],
    [NetworkIntegration::Embed, 'Embedded wall only'],
    [NetworkIntegration::Both, 'API and embedded wall'],
]);
