<?php

use App\Enums\OfferStatus;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\DirectDriver;
use App\Networks\NetworkDriverManager;
use App\Providers\NetworkServiceProvider;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'direct',
        'credentials' => ['postback_secret' => 'direct-secret'],
    ]);
});

it('has no catalogue to sync', function () {
    $driver = new DirectDriver($this->network);

    expect($driver->syncsCatalogue())->toBeFalse()
        ->and(iterator_to_array((function () use ($driver) {
            yield from $driver->fetchOffers();
        })()))->toBe([]);
});

it('leaves hand-entered offers alone when a sync runs', function () {
    $this->app->register(NetworkServiceProvider::class);

    // The trap this covers: the direct network has to be Active to accept
    // postbacks, and `networks:sync` sweeps every active network. A driver with
    // an empty catalogue looks exactly like a network that returned nothing,
    // and the sweep would deactivate every manual offer on it.
    $offer = Offer::factory()->create([
        'network_id' => $this->network->id,
        'status' => OfferStatus::Active,
        'last_seen_at' => now()->subDay(),
    ]);

    (new SyncNetworkOffersJob($this->network))->handle($this->app->make(NetworkDriverManager::class));

    expect($offer->fresh()->status)->toBe(OfferStatus::Active)
        ->and($this->network->fresh()->last_sync_status)->toBeNull();
});

it('reports what is on the shelf instead of testing a connection', function () {
    Offer::factory()->count(2)->create(['network_id' => $this->network->id]);

    $result = (new DirectDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(2);
});

it('puts the click id into the advertiser link', function () {
    $offer = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://advertiser.example/lp?ref=playndgain&c={click_id}',
    ]);

    expect((new DirectDriver($this->network))->buildTrackingUrl($offer, 'abc-123'))
        ->toBe('https://advertiser.example/lp?ref=playndgain&c=abc-123');
});

it('parses a credited postback', function () {
    $dto = (new DirectDriver($this->network))->parsePostback(Request::create('/postback/direct', 'GET', [
        'transaction_id' => 'ADV-9001',
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '12.50',
        'offer_id' => 'promo-1',
    ]));

    expect($dto->networkTxId)->toBe('ADV-9001')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(12.5)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('promo-1');
});

it('reads status 2 as a reversal', function () {
    $dto = (new DirectDriver($this->network))->parsePostback(Request::create('/postback/direct', 'GET', [
        'transaction_id' => 'ADV-9001',
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '12.50',
        'status' => '2',
    ]));

    expect($dto->payout)->toBe(-12.5)
        ->and($dto->status)->toBe('reversed');
});

it('refuses a postback with no transaction id', function () {
    // We wrote this contract, so there is no guessing an idempotency key from
    // click + offer here: a duplicate credit is money that does not come back.
    $dto = (new DirectDriver($this->network))->parsePostback(Request::create('/postback/direct', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '12.50',
    ]));

    expect($dto)->toBeNull();
});

it('fails a postback that carries the wrong secret', function () {
    $driver = new DirectDriver($this->network);

    expect($driver->verifyPostback(Request::create('/postback/direct', 'GET', ['secret' => 'direct-secret'])))->toBeTrue()
        ->and($driver->verifyPostback(Request::create('/postback/direct', 'GET', ['secret' => 'wrong'])))->toBeFalse()
        ->and($driver->verifyPostback(Request::create('/postback/direct')))->toBeFalse();
});

it('fails closed when no secret is configured', function () {
    $this->network->update(['credentials' => []]);

    expect((new DirectDriver($this->network->fresh()))->verifyPostback(
        Request::create('/postback/direct', 'GET', ['secret' => ''])
    ))->toBeFalse();
});
