<?php

use App\Enums\OfferStatus;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\MyChipsDriver;
use App\Networks\NetworkDriverManager;
use App\Providers\NetworkServiceProvider;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'mychips',
        'credentials' => ['adunit_id' => '28617c7e-0178-4b89-b258-74bcf171e'],
    ]);
});

it('has no catalogue to sync', function () {
    $driver = new MyChipsDriver($this->network);

    expect($driver->syncsCatalogue())->toBeFalse()
        ->and(iterator_to_array((function () use ($driver) {
            yield from $driver->fetchOffers();
        })()))->toBe([]);
});

it('leaves offers alone when a sync runs', function () {
    $this->app->register(NetworkServiceProvider::class);

    $offer = Offer::factory()->create([
        'network_id' => $this->network->id,
        'status' => OfferStatus::Active,
        'last_seen_at' => now()->subDay(),
    ]);

    (new SyncNetworkOffersJob($this->network))->handle($this->app->make(NetworkDriverManager::class));

    expect($offer->fresh()->status)->toBe(OfferStatus::Active)
        ->and($this->network->fresh()->last_sync_status)->toBeNull();
});

it('reports that there is no catalogue to fetch', function () {
    $result = (new MyChipsDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(0);
});

it('parses a credited postback', function () {
    $dto = (new MyChipsDriver($this->network))->parsePostback(Request::create('/postback/mychips', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '0.40',
        'user_id' => 'U9221',
        'event_name' => 'install',
        'campaign_id' => 'c-100',
        'campaign_name' => 'Puzzle Quest',
    ]));

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(0.4)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('c-100')
        ->and($dto->offerName)->toBe('install')
        ->and($dto->endUserId)->toBe('U9221')
        ->and($dto->networkTxId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f:install:c-100:0.4');
});

it('accepts a cpi follow-up with payout zero', function () {
    $dto = (new MyChipsDriver($this->network))->parsePostback(Request::create('/postback/mychips', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '0',
        'user_payout' => '0.15',
        'event_name' => 'level_5',
        'campaign_id' => 'c-100',
    ]));

    expect($dto->payout)->toBe(0.0)
        ->and($dto->status)->toBe('credited');
});

it('reads the click id from aff_sub1 when click_id is missing', function () {
    $dto = (new MyChipsDriver($this->network))->parsePostback(Request::create('/postback/mychips', 'GET', [
        'aff_sub1' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '1.00',
        'event_name' => 'install',
        'campaign_id' => 'c-100',
    ]));

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f');
});

it('negates a deduction that arrives with a positive payout', function () {
    $credit = (new MyChipsDriver($this->network))->parsePostback(Request::create('/postback/mychips', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '0.50',
        'event_name' => 'install',
        'campaign_id' => 'c-100',
        'postback_id' => 'pb-credit',
    ]));

    $deduction = (new MyChipsDriver($this->network))->parsePostback(Request::create('/postback/mychips', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '0.50',
        'event_name' => 'install',
        'campaign_id' => 'c-100',
        'postback_id' => 'pb-deduction',
        'kind' => 'deduction',
    ]));

    expect($deduction->status)->toBe('reversed')
        ->and($deduction->payout)->toBe(-0.5)
        ->and($deduction->networkTxId)->toBe($credit->networkTxId);
});

it('marks a rejected fire without crediting', function () {
    $dto = (new MyChipsDriver($this->network))->parsePostback(Request::create('/postback/mychips', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '0',
        'user_payout' => '0',
        'rejected_reason_id' => '1',
        'event_name' => 'install',
        'campaign_id' => 'c-100',
    ]));

    expect($dto->status)->toBe('rejected')
        ->and($dto->payout)->toBe(0.0);
});

it('refuses a postback with no payout', function () {
    expect((new MyChipsDriver($this->network))->parsePostback(Request::create('/postback/mychips', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'event_name' => 'install',
    ])))->toBeNull();
});

it('refuses a postback with nothing to key on', function () {
    expect((new MyChipsDriver($this->network))->parsePostback(Request::create('/postback/mychips', 'GET', [
        'payout' => '0.40',
    ])))->toBeNull();
});

it('has no signature to check', function () {
    expect((new MyChipsDriver($this->network))->verifyPostback(Request::create('/postback/mychips')))->toBeTrue();
});
