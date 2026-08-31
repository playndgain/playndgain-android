<?php

use App\Models\Network;
use App\Networks\Drivers\MonlixDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'monlix',
        'credentials' => ['api_key' => '8812', 'secret_key' => 'monlix-secret'],
    ]);

    $this->postback = [
        'transaction_id' => 'MNX-77120',
        'subid' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'offer_id' => '90211',
        'offer_name' => 'Solitaire Grand Harvest - Level 40',
        'user_id' => 'end-user-42',
        'payout' => '12.750000',
        'ip' => '41.238.10.4',
        'status' => '1',
        'secret' => 'monlix-secret',
    ];
});

it('maps a recorded catalogue into normalized offers', function () {
    Http::fake([
        'api.monlix.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/monlix-offers.json'))),
    ]);

    $offers = iterator_to_array((new MonlixDriver($this->network))->fetchOffers(), false);

    expect($offers)->toHaveCount(2);

    expect($offers[0]->externalId)->toBe('90211')
        ->and($offers[0]->payout)->toBe(12.75)
        ->and($offers[0]->countries)->toBe(['US', 'CA', 'AU'])
        // iphone and ipad collapse to one device vocabulary entry.
        ->and($offers[0]->devices)->toBe(['ios'])
        ->and($offers[0]->categories)->toBe(['Games'])
        ->and($offers[0]->isMultiReward)->toBeTrue()
        // Monlix is the one network that nests the figure a level down, under
        // `payouts[0]` — the dot path in goalList() is there for this row.
        ->and($offers[0]->goals)->toBe([
            ['name' => 'Reach level 10', 'payout' => 4.0],
            ['name' => 'Reach level 40', 'payout' => 8.75],
        ])
        ->and($offers[0]->trackingUrl)->toContain('subid={click_id}');

    expect($offers[1]->countries)->toBe([])
        ->and($offers[1]->devices)->toBe([])
        ->and($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->goals)->toBe([])
        ->and($offers[1]->iconUrl)->toBeNull();
});

it('tokenises the catalogue request', function () {
    Http::fake([
        'api.monlix.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/monlix-offers.json'))),
    ]);

    iterator_to_array((new MonlixDriver($this->network))->fetchOffers(), false);

    Http::assertSent(fn ($request) => $request['appid'] === '8812'
        && $request['token'] === md5('8812monlix-secret'));
});

it('parses a credited postback', function () {
    $dto = (new MonlixDriver($this->network))
        ->parsePostback(Request::create('/postback/monlix', 'GET', $this->postback));

    expect($dto->networkTxId)->toBe('MNX-77120')
        ->and($dto->clickId)->toBe($this->postback['subid'])
        ->and($dto->payout)->toBe(12.75)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('90211')
        ->and($dto->endUserId)->toBe('end-user-42');
});

it('compares the dashboard secret they echo, not an hmac', function () {
    $driver = new MonlixDriver($this->network);

    // Their ClaimBits / Vie samples compare the query secret to the dashboard
    // value. Changing payout used to fail the invented HMAC; it must not now.
    expect($driver->verifyPostback(Request::create('/postback/monlix', 'GET', $this->postback)))->toBeTrue()
        ->and($driver->verifyPostback(Request::create('/postback/monlix', 'GET', [...$this->postback, 'payout' => '1270.00'])))->toBeTrue()
        ->and($driver->verifyPostback(Request::create('/postback/monlix', 'GET', [...$this->postback, 'secret' => 'wrong'])))->toBeFalse()
        ->and($driver->verifyPostback(Request::create('/postback/monlix', 'GET', [
            ...$this->postback,
            'secret' => 'wrong',
            'signature' => hash_hmac('sha256', 'MNX-77120|end-user-42|12.750000', 'monlix-secret'),
        ])))->toBeFalse();
});

it('fails closed when the secret is missing', function () {
    $this->network->update(['credentials' => ['api_key' => '8812']]);

    expect((new MonlixDriver($this->network->fresh()))->verifyPostback(
        Request::create('/postback/monlix', 'GET', $this->postback)
    ))->toBeFalse();
});
