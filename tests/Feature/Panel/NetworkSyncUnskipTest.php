<?php

use App\Enums\SyncStatus;
use App\Filament\Admin\Resources\NetworkSyncs\Pages\ListNetworkSyncs;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\Network;
use App\Models\NetworkSync;
use App\Models\User;
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

it('unskips sample ids from a sync run onto the network and queues a sync', function () {
    $network = Network::factory()->create(['driver' => 'fake']);
    $sync = NetworkSync::create([
        'network_id' => $network->id,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'duration_ms' => 800,
        'offers_seen' => 3,
        'offers_created' => 2,
        'offers_updated' => 0,
        'offers_deactivated' => 0,
        'offers_skipped' => 1,
        'skip_reasons' => [
            'targeting dimension the wall cannot filter on' => [
                'count' => 1,
                'samples' => ['2'],
            ],
        ],
        'status' => SyncStatus::Ok,
        'created_at' => now(),
    ]);

    Livewire::test(ListNetworkSyncs::class)
        ->callTableAction('unskip', $sync);

    expect($network->fresh()->unskipped_external_ids)->toBe(['2']);

    Queue::assertPushed(
        SyncNetworkOffersJob::class,
        fn (SyncNetworkOffersJob $job): bool => $job->network->is($network),
    );
});
