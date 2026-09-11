<?php

use App\Enums\NetworkIntegration;
use App\Enums\OfferStatus;
use App\Enums\SyncStatus;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\Network;
use App\Models\NetworkSync;
use App\Models\Offer;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\DTO\OfferDTO;
use App\Networks\NetworkDriverManager;
use App\Providers\NetworkServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

beforeEach(function () {
    $this->app->register(NetworkServiceProvider::class);
    FakeNetworkDriver::reset();

    $this->network = Network::factory()->create(['driver' => 'fake', 'offers_count' => 0]);

    $this->sync = fn () => (new SyncNetworkOffersJob($this->network))
        ->handle($this->app->make(NetworkDriverManager::class));
});

it('creates the catalogue and records the run', function () {
    FakeNetworkDriver::shouldReturnOffers(FakeNetworkDriver::makeOffers(3));

    ($this->sync)();

    $this->network->refresh();

    expect(Offer::query()->where('network_id', $this->network->id)->count())->toBe(3)
        ->and((int) $this->network->offers_count)->toBe(3)
        ->and($this->network->last_sync_status)->toBe(SyncStatus::Ok)
        ->and($this->network->last_sync_error)->toBeNull()
        ->and($this->network->last_synced_at)->not->toBeNull();

    $offer = Offer::query()->where('external_id', 'fake-1')->sole();

    expect($offer->status)->toBe(OfferStatus::Active)
        ->and((float) $offer->payout)->toBe(1.0)
        ->and($offer->first_seen_at)->not->toBeNull()
        ->and($offer->tracking_url)->toContain('{click_id}');

    // The Sync Log row — docs/07 §4. `networks` remembers only the last run,
    // and the run that emptied a wall is rarely the last one.
    $run = NetworkSync::query()->sole();

    expect($run->network_id)->toBe($this->network->id)
        ->and($run->status)->toBe(SyncStatus::Ok)
        ->and($run->error)->toBeNull()
        ->and((int) $run->offers_seen)->toBe(3)
        ->and((int) $run->offers_created)->toBe(3)
        ->and((int) $run->offers_updated)->toBe(0)
        ->and((int) $run->offers_deactivated)->toBe(0)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('deactivates offers the network stopped returning without deleting them', function () {
    $vanished = Offer::factory()->create([
        'network_id' => $this->network->id,
        'external_id' => 'gone-1',
        'status' => OfferStatus::Active,
        'last_seen_at' => now()->subMinutes(5),
    ]);

    $kept = Offer::factory()->create([
        'network_id' => $this->network->id,
        'external_id' => 'fake-1',
        'status' => OfferStatus::Active,
        'last_seen_at' => now()->subMinutes(5),
    ]);

    FakeNetworkDriver::shouldReturnOffers(FakeNetworkDriver::makeOffers(2));

    ($this->sync)();

    expect($vanished->fresh()->status)->toBe(OfferStatus::Inactive)
        ->and($kept->fresh()->status)->toBe(OfferStatus::Active)
        ->and(Offer::query()->where('network_id', $this->network->id)->count())->toBe(3);

    // New inventory, refreshed inventory and inventory that left the wall, told
    // apart. "47 offers deactivated at 03:00" is the whole point of the log.
    $run = NetworkSync::query()->sole();

    expect((int) $run->offers_seen)->toBe(2)
        ->and((int) $run->offers_created)->toBe(1)
        ->and((int) $run->offers_updated)->toBe(1)
        ->and((int) $run->offers_deactivated)->toBe(1);
});

it('does not fetch when the admin chose an embedded wall only', function () {
    $this->network->update(['integration' => NetworkIntegration::Embed]);
    FakeNetworkDriver::shouldReturnOffers(FakeNetworkDriver::makeOffers(3));

    ($this->sync)();

    expect(Offer::query()->where('network_id', $this->network->id)->count())->toBe(0)
        ->and($this->network->fresh()->last_sync_status)->toBeNull();
});

it('leaves an admin-blocked offer blocked', function () {
    $blocked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'external_id' => 'fake-1',
        'status' => OfferStatus::Blocked,
        'blocked_reason' => 'Misleading creative',
        'last_seen_at' => now()->subMinutes(5),
    ]);

    FakeNetworkDriver::shouldReturnOffers(FakeNetworkDriver::makeOffers(1));

    ($this->sync)();

    expect($blocked->fresh()->status)->toBe(OfferStatus::Blocked);
});

it('records a failed sync and rethrows', function () {
    FakeNetworkDriver::shouldThrowOnFetch(new RuntimeException('Network API is down'));

    expect($this->sync)->toThrow(RuntimeException::class);

    $this->network->refresh();

    expect($this->network->last_sync_status)->toBe(SyncStatus::Failed)
        ->and($this->network->last_sync_error)->toContain('Network API is down')
        ->and($this->network->last_synced_at)->toBeNull();

    // The rethrow must not take the record with it: a run that failed and
    // logged nothing is a run nobody can explain afterwards.
    $run = NetworkSync::query()->sole();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('Network API is down')
        ->and((int) $run->offers_deactivated)->toBe(0);
});

it('refuses to deactivate a collapsed catalogue and raises an alert', function () {
    Log::spy();

    $this->network->update(['offers_count' => 100]);

    Offer::factory()->count(5)->create([
        'network_id' => $this->network->id,
        'status' => OfferStatus::Active,
        'last_seen_at' => now()->subMinutes(5),
    ]);

    FakeNetworkDriver::shouldReturnOffers(FakeNetworkDriver::makeOffers(3));

    ($this->sync)();

    $this->network->refresh();

    expect(Offer::query()->where('network_id', $this->network->id)->where('status', OfferStatus::Active)->count())->toBe(8)
        ->and($this->network->last_sync_status)->toBe(SyncStatus::Failed)
        ->and($this->network->last_sync_error)->toContain('Catalogue dropped from 100 to 3')
        ->and($this->network->last_synced_at)->toBeNull()
        ->and((int) $this->network->offers_count)->toBe(100);

    Log::shouldHaveReceived('critical')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, 'network.catalogue_collapse')
            && $context['previous'] === 100
            && $context['current'] === 3
    );

    // The abandoned run is precisely the one an operator goes looking for, so
    // it gets a row like any other — with nothing deactivated, which is the
    // fact the guard exists to produce.
    $run = NetworkSync::query()->sole();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('Catalogue dropped from 100 to 3')
        ->and((int) $run->offers_seen)->toBe(3)
        ->and((int) $run->offers_deactivated)->toBe(0);
});

it('still deactivates when the previous catalogue was too small to judge', function () {
    $this->network->update(['offers_count' => 20]);

    $vanished = Offer::factory()->create([
        'network_id' => $this->network->id,
        'external_id' => 'gone-1',
        'status' => OfferStatus::Active,
        'last_seen_at' => now()->subMinutes(5),
    ]);

    FakeNetworkDriver::shouldReturnOffers(FakeNetworkDriver::makeOffers(3));

    ($this->sync)();

    expect($vanished->fresh()->status)->toBe(OfferStatus::Inactive)
        ->and($this->network->refresh()->last_sync_status)->toBe(SyncStatus::Ok);
});

it('stores an oversized icon url whole and clamps an oversized name', function () {
    // The failure this covers: one row a network padded past our column killed
    // the insert, and with it the entire catalogue run for that network.
    $icon = 'https://cdn.example.com/i/'.str_repeat('a', 400).'.png';

    FakeNetworkDriver::shouldReturnOffers([
        new OfferDTO(
            externalId: 'long-1',
            name: str_repeat('Very Long Offer Title ', 30),
            trackingUrl: 'https://example.com/click?s1={click_id}',
            payout: 1.0,
            iconUrl: $icon,
            previewUrl: $icon,
        ),
    ]);

    ($this->sync)();

    $offer = Offer::query()->where('external_id', 'long-1')->sole();

    // A shortened URL is a broken one, so it is stored whole.
    expect($offer->icon_url)->toBe($icon)
        ->and($offer->preview_url)->toBe($icon)
        // A clipped title is cosmetic; a failed sync is total.
        ->and(mb_strlen((string) $offer->name))->toBe(255)
        ->and($this->network->refresh()->last_sync_status)->toBe(SyncStatus::Ok);
});

it('persists the steps a multi-reward offer is made of', function () {
    // Every driver parsed these and threw them away before `offers.goals`
    // existed, which is why the wall could say "pays more than once" and never
    // what the something-more-than-once was.
    FakeNetworkDriver::shouldReturnOffers([
        new OfferDTO(
            externalId: 'steps-1',
            name: 'Village Builder',
            trackingUrl: 'https://example.com/click?s1={click_id}',
            payout: 3.0,
            isMultiReward: true,
            goals: [
                ['name' => 'Reach village 5', 'payout' => 2.0],
                ['name' => 'Reach village 15', 'payout' => 1.0],
            ],
        ),
        new OfferDTO(
            externalId: 'steps-2',
            name: 'One And Done',
            trackingUrl: 'https://example.com/click?s1={click_id}',
            payout: 1.0,
        ),
    ]);

    ($this->sync)();

    $goals = Offer::query()->where('external_id', 'steps-1')->sole()->goals;

    // Asserted through the JSON round trip rather than against it: the column
    // drops a zero fraction, so 2.0 comes back as 2 and every reader casts.
    expect($goals)->toHaveCount(2)
        ->and($goals[0]['name'])->toBe('Reach village 5')
        ->and($goals[1]['name'])->toBe('Reach village 15')
        // The property the detail view depends on: the steps add up to the
        // headline, or the page quotes a total its own list contradicts.
        ->and((float) array_sum(array_column($goals, 'payout')))->toBe(3.0)
        // An offer with one step has no steps to list — the offer is the step,
        // and a synthetic goal per row is a column full of noise.
        ->and(Offer::query()->where('external_id', 'steps-2')->sole()->goals)->toBeNull();
});

it('emails only once a network sync has failed three times running', function () {
    Log::spy();

    FakeNetworkDriver::shouldThrowOnFetch(new RuntimeException('upstream 503'));

    // One failure is a network having a bad minute; the sweep runs every
    // fifteen. docs/11 §7 puts the threshold at three in a row.
    foreach (range(1, 2) as $ignored) {
        try {
            ($this->sync)();
        } catch (Throwable) {
        }
    }

    Log::shouldNotHaveReceived('critical', [Mockery::pattern('/network\.sync_failing/'), Mockery::any()]);

    try {
        ($this->sync)();
    } catch (Throwable) {
    }

    Log::shouldHaveReceived('critical')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, 'network.sync_failing')
            && $context['consecutive'] === 3
    );

    expect(NetworkSync::query()->where('status', SyncStatus::Failed)->count())->toBe(3);
});

it('does not count a failure streak broken by a good run', function () {
    Log::spy();

    FakeNetworkDriver::shouldThrowOnFetch(new RuntimeException('upstream 503'));

    foreach (range(1, 2) as $ignored) {
        try {
            ($this->sync)();
        } catch (Throwable) {
        }
    }

    // A network that recovers and fails again is not a network we have stopped
    // receiving. The streak has to be consecutive or the alert fires on noise.
    FakeNetworkDriver::reset();
    FakeNetworkDriver::shouldReturnOffers(FakeNetworkDriver::makeOffers(3));
    ($this->sync)();

    FakeNetworkDriver::shouldThrowOnFetch(new RuntimeException('upstream 503'));

    try {
        ($this->sync)();
    } catch (Throwable) {
    }

    Log::shouldNotHaveReceived('critical', [Mockery::pattern('/network\.sync_failing/'), Mockery::any()]);
});

it('records a run for a network whose driver class no longer exists', function () {
    // Four drivers were removed on this branch. A row still naming one of them
    // used to throw before any status was written, so the failure lived only in
    // `failed_jobs`: no sync row, no Failed status, and the three-strike alert
    // could never fire because there was nothing to count.
    $orphan = Network::factory()->create(['driver' => 'gone-driver']);

    try {
        (new SyncNetworkOffersJob($orphan))->handle($this->app->make(NetworkDriverManager::class));
        $this->fail('An unknown driver key must still rethrow, so the queue records the failure.');
    } catch (Throwable) {
        // Expected. What matters is what was written on the way out.
    }

    $orphan->refresh();

    expect($orphan->last_sync_status)->toBe(SyncStatus::Failed)
        ->and($orphan->last_sync_error)->not->toBeNull();

    $run = NetworkSync::query()->where('network_id', $orphan->id)->sole();

    expect($run->status)->toBe(SyncStatus::Failed);
});

it('records what a driver refused and why, alongside what it kept', function () {
    // A real platform catalogue: some offers priced along a dimension the wall
    // cannot filter on, and the operator needs the shortfall explained rather
    // than inferred.
    $network = Network::factory()->platform('affise')->create([
        'credentials' => [
            'api_base_url' => 'https://api-acme.affise.com',
            'api_key' => 'affise-key',
            'postback_secret' => 'affise-postback-secret',
        ],
    ]);

    Http::fakeSequence()
        ->push(json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/affise-offers-page1.json')), true))
        ->push(json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/affise-offers-page2.json')), true));

    (new SyncNetworkOffersJob($network))->handle($this->app->make(NetworkDriverManager::class));

    $run = NetworkSync::query()->where('network_id', $network->id)->sole();

    expect($run->status)->toBe(SyncStatus::Ok)
        ->and($run->offers_seen)->toBe(5)
        // Refusals, not dropped offers: a percent-priced offer records both the
        // payment it refused and the empty variant list that leaves behind.
        ->and($run->offers_skipped)->toBe(9)
        ->and($run->skip_reasons)->toHaveKeys([
            'percentage payout',
            'country exclusion list',
            'non-USD payout',
            'offer stopped',
        ])
        ->and($run->skip_reasons['percentage payout']['count'])->toBe(1)
        ->and($run->skip_reasons['percentage payout']['samples'])->toBe(['5718dac83b7d9bf8588b45bb'])
        // The tooltip an admin reads, worst offender first.
        ->and($run->skipSummary())->toContain('percentage payout: 1');
});

it('keeps the reasons a failed run had already collected', function () {
    $network = Network::factory()->platform('affise')->create([
        'credentials' => [
            'api_base_url' => 'https://api-acme.affise.com',
            'api_key' => 'affise-key',
            'postback_secret' => 'affise-postback-secret',
        ],
    ]);

    Offer::factory()->count(3)->create(['network_id' => $network->id, 'status' => OfferStatus::Active]);

    Http::fake(['*' => Http::response(['status' => 0, 'message' => 'Invalid API key'], 403)]);

    expect(fn () => (new SyncNetworkOffersJob($network))->handle($this->app->make(NetworkDriverManager::class)))
        ->toThrow(RuntimeException::class);

    $run = NetworkSync::query()->where('network_id', $network->id)->sole();

    // The whole point of fetching every page before yielding: a catalogue that
    // failed deactivates nothing, and the run that failed is still on the log.
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and(Offer::query()->where('status', OfferStatus::Active)->count())->toBe(3)
        ->and($run->offers_skipped)->toBe(0);
});
