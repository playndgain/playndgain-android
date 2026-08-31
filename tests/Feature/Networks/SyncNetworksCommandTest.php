<?php

use App\Enums\NetworkStatus;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\Network;
use Illuminate\Support\Facades\Queue;

it('queues a sync only for active networks that are due', function () {
    Queue::fake();

    $overdue = Network::factory()->create([
        'status' => NetworkStatus::Active,
        'sync_interval_minutes' => 60,
        'last_synced_at' => now()->subHours(2),
    ]);

    $neverSynced = Network::factory()->create([
        'status' => NetworkStatus::Active,
        'last_synced_at' => null,
    ]);

    Network::factory()->create([
        'status' => NetworkStatus::Active,
        'sync_interval_minutes' => 60,
        'last_synced_at' => now()->subMinutes(5),
    ]);

    Network::factory()->disabled()->create(['last_synced_at' => null]);

    $this->artisan('networks:sync')->assertSuccessful();

    Queue::assertPushed(SyncNetworkOffersJob::class, 2);
    Queue::assertPushedOn('sync', SyncNetworkOffersJob::class);

    foreach ([$overdue, $neverSynced] as $network) {
        Queue::assertPushed(
            SyncNetworkOffersJob::class,
            fn (SyncNetworkOffersJob $job) => $job->network->is($network)
        );
    }
});
