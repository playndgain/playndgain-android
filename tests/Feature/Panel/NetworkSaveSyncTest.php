<?php

use App\Enums\NetworkIntegration;
use App\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use App\Filament\Admin\Resources\Networks\Pages\ListNetworks;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    Queue::fake();
});

it('flushes the wall cache when a network is saved without queuing a sync', function () {
    $network = Network::factory()->create(['driver' => 'fake']);

    Cache::tags("network:{$network->id}")->put('offers', ['stale'], 60);
    Cache::put('wall:active-networks', ['stale'], 60);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['name' => 'Renamed Co'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Cache::tags("network:{$network->id}")->get('offers'))->toBeNull()
        ->and(Cache::get('wall:active-networks'))->toBeNull();

    Queue::assertNotPushed(SyncNetworkOffersJob::class);
});

it('queues a catalogue sync after credentials change', function () {
    $network = Network::factory()->create([
        'driver' => 'fake',
        'credentials' => ['api_key' => 'old-key'],
    ]);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['credentials' => ['api_key' => 'new-key']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($network->fresh()->credentials)->toBe(['api_key' => 'new-key']);

    Queue::assertPushedOn('sync', SyncNetworkOffersJob::class);
    Queue::assertPushed(
        SyncNetworkOffersJob::class,
        fn (SyncNetworkOffersJob $job): bool => $job->network->is($network) && $job->afterCommit,
    );
});

it('queues a catalogue sync when integration starts using the catalogue', function () {
    $network = Network::factory()->create([
        'driver' => 'fake',
        'integration' => NetworkIntegration::Embed,
        'hosts_wall' => true,
        'wall_url' => 'https://p.test/w?s={sub_id}',
    ]);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm([
            'integration' => NetworkIntegration::Both->value,
            'wall_url' => 'https://p.test/w?s={sub_id}',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Queue::assertPushed(
        SyncNetworkOffersJob::class,
        fn (SyncNetworkOffersJob $job): bool => $job->network->is($network),
    );
});

it('does not queue a sync when switching to an embedded wall', function () {
    $network = Network::factory()->create(['driver' => 'fake']);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm([
            'integration' => NetworkIntegration::Embed->value,
            'wall_url' => 'https://p.test/w?s={sub_id}',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Queue::assertNotPushed(SyncNetworkOffersJob::class);
});

it('flushes the wall cache when a network is disabled', function () {
    $network = Network::factory()->create(['driver' => 'fake']);

    Cache::tags("network:{$network->id}")->put('offers', ['stale'], 60);
    Cache::put('wall:active-networks', ['stale'], 60);

    Livewire::test(ListNetworks::class)
        ->callTableAction('toggle', $network);

    expect($network->fresh()->isActive())->toBeFalse()
        ->and(Cache::tags("network:{$network->id}")->get('offers'))->toBeNull()
        ->and(Cache::get('wall:active-networks'))->toBeNull();
});

it('saves unskipped offer ids and queues a catalogue sync', function () {
    $network = Network::factory()->create(['driver' => 'fake']);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['unskipped_external_ids' => ['2', '1008']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($network->fresh()->unskipped_external_ids)->toBe(['2', '1008']);

    Queue::assertPushed(
        SyncNetworkOffersJob::class,
        fn (SyncNetworkOffersJob $job): bool => $job->network->is($network),
    );
});
