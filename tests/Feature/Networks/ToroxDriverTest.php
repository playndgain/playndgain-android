<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\ToroxDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'torox',
        'credentials' => ['pubid' => '7710', 'appid' => '31', 'secretkey' => 'torox-secret'],
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    Http::fake([
        'torox.io/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/torox-offers.json'))),
    ]);

    $offers = iterator_to_array((new ToroxDriver($this->network))->fetchOffers(), false);

    expect($offers)->toHaveCount(3);

    expect($offers[0]->externalId)->toBe('40218')
        ->and($offers[0]->name)->toBe('Rise of Kingdoms - Reach City Hall 10')
        ->and($offers[0]->payout)->toBe(6.4)
        ->and($offers[0]->description)->toBe('Install and reach City Hall level 10.')
        ->and($offers[0]->iconUrl)->toBe('https://cdn.torox.io/offers/40218.png')
        ->and($offers[0]->countries)->toBe(['US', 'GB'])
        ->and($offers[0]->devices)->toBe(['android'])
        // `payout_type` is the conversion type, not a category.
        ->and($offers[0]->conversionType)->toBe('cpi')
        ->and($offers[0]->categories)->toBe([])
        // The literal `user=1` slot is filled rather than duplicated.
        ->and($offers[0]->trackingUrl)->toBe('https://torox.io/click/40218?pubid=7710&user={click_id}');

    // `all` is not a device we know, and an empty device list is our vocabulary
    // for "every device".
    expect($offers[1]->devices)->toBe([])
        ->and($offers[1]->countries)->toBe([])
        ->and($offers[1]->conversionType)->toBe('cpl')
        ->and($offers[1]->trackingUrl)->toBe('https://torox.io/click/40331?pubid=7710&sub={click_id}');

    // A payout type the enum does not know is a `cpa`, not a failed sync.
    expect($offers[2]->conversionType)->toBe('cpa')
        ->and($offers[2]->countries)->toBe(['DE'])
        // Nowhere to put the click id, so a slot is opened for it.
        ->and($offers[2]->trackingUrl)->toBe('https://torox.io/click/40412?user_id={click_id}');
});

it('sends the three credentials in the query', function () {
    Http::fake([
        'torox.io/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/torox-offers.json'))),
    ]);

    iterator_to_array((new ToroxDriver($this->network))->fetchOffers(), false);

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('pubid=7710')
            ->and($request->url())->toContain('appid=31')
            ->and($request->url())->toContain('secretkey=torox-secret');

        return str_contains($request->url(), 'format=json');
    });
});

it('fails the connection test on an http error', function () {
    Http::fake(['torox.io/*' => Http::response('nope', 500)]);

    $result = (new ToroxDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 500');
});

it('refuses to read an api-level rejection as an empty catalogue', function () {
    // Torox answers a bad key with HTTP 200 and no offers array. Reporting
    // "connected, 0 offers" would green-light a network that syncs nothing, and
    // syncing zero offers would deactivate the whole catalogue.
    Http::fake([
        'torox.io/*' => Http::response(['response' => ['error' => 'Invalid secretkey']]),
    ]);

    $driver = new ToroxDriver($this->network);
    $result = $driver->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Invalid secretkey');

    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'Invalid secretkey');
});

it('parses a credited postback', function () {
    $dto = (new ToroxDriver($this->network))->parsePostback(Request::create('/postback/torox', 'GET', [
        'user_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'oid' => '40218',
        'o_name' => 'Rise of Kingdoms - Reach City Hall 10',
        'amount' => '640',
        'ip_address' => '203.0.113.9',
        'payout' => '6.40',
    ]));

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        // No transaction id from Torox — click plus offer is the key.
        ->and($dto->networkTxId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f:40218')
        ->and($dto->payout)->toBe(6.4)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('40218')
        ->and($dto->offerName)->toBe('Rise of Kingdoms - Reach City Hall 10')
        ->and($dto->ip)->toBe('203.0.113.9')
        // `user_id` is our click id coming back, not an end user of theirs.
        ->and($dto->endUserId)->toBeNull();
});

it('reads the campaign_id spelling of the same parameters', function () {
    $dto = (new ToroxDriver($this->network))->parsePostback(Request::create('/postback/torox', 'GET', [
        'user_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'campaign_id' => '40218',
        'campaign_name' => 'Rise of Kingdoms - Reach City Hall 10',
        'ip' => '203.0.113.9',
        'payout' => '6.40',
    ]));

    expect($dto->offerExternalId)->toBe('40218')
        ->and($dto->offerName)->toBe('Rise of Kingdoms - Reach City Hall 10')
        ->and($dto->ip)->toBe('203.0.113.9');
});

it('gives a reversal the same key its credit had', function () {
    $driver = new ToroxDriver($this->network);
    $payload = ['user_id' => 'click-uuid', 'oid' => '40218'];

    $credit = $driver->parsePostback(Request::create('/postback/torox', 'GET', [...$payload, 'payout' => '6.40']));
    $reversal = $driver->parsePostback(Request::create('/postback/torox', 'GET', [...$payload, 'payout' => '-6.40']));

    // The chargeback is looked up by network_txid, so the two have to agree.
    expect($reversal->networkTxId)->toBe($credit->networkTxId)
        ->and($reversal->payout)->toBe(-6.4)
        ->and($reversal->status)->toBe('reversed');
});

it('does not parse a postback with no subid', function () {
    expect((new ToroxDriver($this->network))->parsePostback(Request::create('/postback/torox', 'GET', [
        'oid' => '40218',
        'payout' => '6.40',
    ])))->toBeNull();
});

it('puts the click id in the subid slot', function () {
    $driver = new ToroxDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://torox.io/click/40218?pubid=7710&user={click_id}',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://torox.io/click/40218?pubid=7710&user=click-uuid');

    // A link with nowhere to put the id is never handed over untouched: an
    // untracked click is a conversion nobody can be paid for.
    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://torox.io/click/40412',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://torox.io/click/40412?user_id=click-uuid');
});
