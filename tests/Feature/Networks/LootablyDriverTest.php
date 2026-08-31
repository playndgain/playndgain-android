<?php

use App\Models\Network;
use App\Networks\Drivers\LootablyDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'lootably',
        'credentials' => ['api_key' => 'lootably-key', 'placement_id' => 'plc_1'],
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    Http::fake([
        'api.lootably.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/lootably-offers.json'))),
    ]);

    $offers = iterator_to_array((new LootablyDriver($this->network))->fetchOffers(), false);

    // The variable-revenue row is dropped: there is no price to pay a share of.
    expect($offers)->toHaveCount(2);

    expect($offers[0]->externalId)->toBe('a1b2c3')
        // Multi-step offers price each goal.
        ->and($offers[0]->payout)->toBe(6.0)
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->countries)->toBe(['US', 'DE'])
        ->and($offers[0]->devices)->toBe(['android', 'ios'])
        ->and($offers[0]->trackingUrl)->toBe('https://wall.lootably.com/offer/a1b2c3?placementID=plc_1&userID={click_id}');

    expect($offers[1]->payout)->toBe(9.25)
        ->and($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->countries)->toBe([])
        // The `{userID}` template form resolves to the same macro.
        ->and($offers[1]->trackingUrl)->toBe('https://wall.lootably.com/offer/d4e5f6?placementID=plc_1&userID={click_id}');
});

it('posts the credentials as the request body', function () {
    Http::fake([
        'api.lootably.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/lootably-offers.json'))),
    ]);

    iterator_to_array((new LootablyDriver($this->network))->fetchOffers(), false);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['apiKey'] === 'lootably-key'
        && $request['placementID'] === 'plc_1');
});

it('parses a credited postback', function () {
    $dto = (new LootablyDriver($this->network))->parsePostback(Request::create('/postback/lootably', 'GET', [
        'transactionID' => 'LTB-4410',
        'offerID' => 'a1b2c3',
        'userID' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'revenue' => '6.00',
    ]));

    expect($dto->networkTxId)->toBe('LTB-4410')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(6.0)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('a1b2c3');
});

it('reads negative revenue as a reversal', function () {
    $dto = (new LootablyDriver($this->network))->parsePostback(Request::create('/postback/lootably', 'GET', [
        'transactionID' => 'LTB-4410',
        'userID' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'revenue' => '-6.00',
    ]));

    expect($dto->payout)->toBe(-6.0)
        ->and($dto->status)->toBe('reversed');
});

it('rejects a tampered hash', function () {
    $driver = new LootablyDriver($this->network);
    $payload = ['transactionID' => 'LTB-4410', 'revenue' => '6.00'];
    $hash = hash_hmac('sha256', 'LTB-44106.00', 'lootably-key');

    expect($driver->verifyPostback(Request::create('/postback/lootably', 'GET', [...$payload, 'hash' => $hash])))->toBeTrue()
        ->and($driver->verifyPostback(Request::create('/postback/lootably', 'GET', [...$payload, 'revenue' => '600.00', 'hash' => $hash])))->toBeFalse();
});
