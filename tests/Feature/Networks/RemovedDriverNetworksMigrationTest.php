<?php

use App\Enums\IncomingPostbackResult;
use App\Enums\NetworkStatus;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\IncomingPostback;
use App\Models\Network;
use App\Models\Offer;
use Illuminate\Support\Facades\Queue;

/**
 * RefreshDatabase has already run this migration against an empty `networks`
 * table, so the pre-branch rows are seeded afterwards and the migration is
 * invoked by hand.
 */
beforeEach(function () {
    $this->migration = require database_path('migrations/2026_01_06_000002_disable_networks_for_removed_drivers.php');

    $this->orphan = Network::factory()->create([
        'driver' => 'revlum',
        'status' => NetworkStatus::Active,
    ]);
});

it('disables every row whose driver was removed', function () {
    $rows = collect(['wannads', 'youmi', 'dtny'])->map(fn (string $driver) => Network::factory()->create([
        'driver' => $driver,
        'status' => NetworkStatus::Active,
    ]))->push($this->orphan);

    $this->migration->up();

    expect(Network::query()->whereIn('driver', ['revlum', 'wannads', 'youmi', 'dtny'])->count())->toBe(4)
        ->and(Network::query()->where('status', NetworkStatus::Active)->count())->toBe(0);

    // The breadcrumb the admin panel surfaces on the row — a network that went
    // quiet with no explanation is the thing this whole finding is about.
    expect($rows->every(fn (Network $row): bool => filled($row->fresh()->last_sync_error)))->toBeTrue()
        // No sync failed, so nothing claims one did.
        ->and($this->orphan->fresh()->last_sync_status)->toBeNull();
});

it('keeps the row, because money references it', function () {
    $offer = Offer::factory()->create(['network_id' => $this->orphan->id]);

    $this->migration->up();

    // `offers`, `offer_clicks` and `conversions` all cascade on delete. Removing
    // the network row would take the audit trail behind real payouts with it.
    expect(Network::query()->whereKey($this->orphan->id)->exists())->toBeTrue()
        ->and(Offer::query()->whereKey($offer->id)->exists())->toBeTrue();
});

it('takes the row out of the sweep that would otherwise throw', function () {
    Queue::fake();

    // Active, so the scheduler queues a job that dies on UnknownDriverException
    // before the sync job writes any status — visible only in `failed_jobs`.
    $this->artisan('networks:sync');
    Queue::assertPushed(SyncNetworkOffersJob::class);

    $this->migration->up();

    Queue::fake();
    $this->artisan('networks:sync');
    Queue::assertNothingPushed();
});

it('answers a postback with a recorded rejection rather than a 404', function () {
    // Already non-silent before the migration — PostbackController catches
    // UnknownDriverException and logs it — but the row reads as a live network
    // that briefly failed. Afterwards it is refused at the not-active gate,
    // which is the recorded rejection the old StubDriver used to give.
    $this->get('/postback/revlum')->assertNotFound();

    $this->migration->up();

    $this->get('/postback/revlum')->assertForbidden();

    expect(IncomingPostback::query()->latest('id')->first()->result)
        ->toBe(IncomingPostbackResult::Rejected);
});

it('leaves a network we still have a driver for alone', function () {
    $live = Network::factory()->create(['driver' => 'monlix', 'status' => NetworkStatus::Active]);

    $this->migration->up();

    expect($live->fresh()->status)->toBe(NetworkStatus::Active)
        ->and($live->fresh()->last_sync_error)->toBeNull();
});

it('settles — a second run changes nothing', function () {
    $this->migration->up();
    $touchedAt = $this->orphan->fresh()->updated_at;

    $this->migration->up();

    expect($this->orphan->fresh()->updated_at->eq($touchedAt))->toBeTrue();
});
