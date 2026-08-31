<?php

use App\Models\Network;
use App\Networks\Drivers\AdGateMediaDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'adgatemedia',
        'credentials' => ['affiliate_id' => '5510', 'api_key' => 'adgate-key', 'wall_code' => 'abcd1234'],
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    Http::fake([
        'api.adgatemedia.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/adgatemedia-offers.json'))),
    ]);

    $offers = iterator_to_array((new AdGateMediaDriver($this->network))->fetchOffers(), false);

    expect($offers)->toHaveCount(2);

    expect($offers[0]->externalId)->toBe('41288')
        // Per-event pricing: the offer is worth the sum of its events.
        ->and($offers[0]->payout)->toBe(4.25)
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->countries)->toBe(['US', 'CA'])
        ->and($offers[0]->devices)->toBe(['android', 'ios'])
        ->and($offers[0]->categories)->toBe(['Games', 'Casual'])
        ->and($offers[0]->instructions)->toContain('New users only.')
        ->and($offers[0]->iconUrl)->toBe('https://cdn.adgatemedia.com/icons/41288.png')
        // The click_url arrives with an empty s1 slot — the macro fills it
        // instead of adding a second, ignored parameter.
        ->and($offers[0]->trackingUrl)->toBe('https://d.adgaterewards.com/offerRedirect/41288?wall_code=abcd1234&s1={click_id}');

    expect($offers[1]->payout)->toBe(1.75)
        ->and($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->countries)->toBe([])
        ->and($offers[1]->devices)->toBe(['desktop'])
        ->and($offers[1]->instructions)->toBeNull()
        ->and($offers[1]->iconUrl)->toBeNull()
        // No slot in the URL at all, so one is added.
        ->and($offers[1]->trackingUrl)->toContain('&s1={click_id}');
});

it('authenticates the catalogue request', function () {
    Http::fake([
        'api.adgatemedia.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/adgatemedia-offers.json'))),
    ]);

    iterator_to_array((new AdGateMediaDriver($this->network))->fetchOffers(), false);

    Http::assertSent(fn ($request) => $request['aff'] === '5510'
        && $request['api_key'] === 'adgate-key'
        && $request['wall_code'] === 'abcd1234');
});

/**
 * The sub-id slot is picked by name, not by the shape of the URL's tail.
 *
 * Landing the macro on the wrong parameter leaves `s1` empty, and an empty `s1`
 * on the way back makes `parsePostback()` return null — the conversion is
 * silently unattributed and the publisher is never paid for it. Nothing logs an
 * error on either leg, which is why these shapes are pinned one by one.
 */
it('fills the s1 slot whatever else the click_url carries', function (string $clickUrl, string $expected) {
    Http::fake([
        'api.adgatemedia.com/*' => Http::response(['data' => [
            ['id' => '41288', 'name' => 'Coin Master', 'click_url' => $clickUrl],
        ]]),
    ]);

    $offers = iterator_to_array((new AdGateMediaDriver($this->network))->fetchOffers(), false);

    expect($offers[0]->trackingUrl)->toBe($expected);
})->with([
    'empty s1 last' => [
        'https://d.adgaterewards.com/offerRedirect/41288?wall_code=abcd1234&s1=',
        'https://d.adgaterewards.com/offerRedirect/41288?wall_code=abcd1234&s1={click_id}',
    ],
    // Broken before the fix: the macro went to s5 and s1 stayed empty.
    'a second empty slot after s1' => [
        'https://d.adgaterewards.com/offerRedirect/41288?s1=&s5=',
        'https://d.adgaterewards.com/offerRedirect/41288?s1={click_id}&s5=',
    ],
    // Broken before the fix: the macro became the value of somebody else's
    // parameter, and s1 was never sent at all.
    'a trailing parameter that is not ours' => [
        'https://d.adgaterewards.com/offerRedirect/41288?wall_code=abcd1234&campaign=',
        'https://d.adgaterewards.com/offerRedirect/41288?wall_code=abcd1234&campaign=&s1={click_id}',
    ],
    // s1 is ours. AdGate's own placeholder in it is not a sub-id we can attribute.
    'an s1 that already carries a value' => [
        'https://d.adgaterewards.com/offerRedirect/41288?s1=adgate-default&wall_code=abcd1234',
        'https://d.adgaterewards.com/offerRedirect/41288?s1={click_id}&wall_code=abcd1234',
    ],
    'no query string at all' => [
        'https://d.adgaterewards.com/offerRedirect/41288',
        'https://d.adgaterewards.com/offerRedirect/41288?s1={click_id}',
    ],
    // Every other parameter comes out byte-for-byte — re-encoding a nested URL
    // would break the redirect as thoroughly as losing the sub-id does.
    'an encoded value belonging to another parameter' => [
        'https://d.adgaterewards.com/offerRedirect/41288?redirect=https%3A%2F%2Fex.test%3Fa%3D1%26b%3D2&s1=',
        'https://d.adgaterewards.com/offerRedirect/41288?redirect=https%3A%2F%2Fex.test%3Fa%3D1%26b%3D2&s1={click_id}',
    ],
]);

it('parses a credited postback', function () {
    $dto = (new AdGateMediaDriver($this->network))->parsePostback(Request::create('/postback/adgatemedia', 'GET', [
        'offer_id' => '41288',
        'offer_name' => 'Coin Master - Reach Village 15',
        's1' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '3.05',
        'user_id' => 'end-user-42',
    ]));

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(3.05)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('41288')
        // No transaction id from AdGate, so click + offer is the idempotency key.
        ->and($dto->networkTxId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f:41288');
});

it('reads a negative payout as a reversal', function () {
    $dto = (new AdGateMediaDriver($this->network))->parsePostback(Request::create('/postback/adgatemedia', 'GET', [
        'offer_id' => '41288',
        's1' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '-3.05',
    ]));

    expect($dto->payout)->toBe(-3.05)
        ->and($dto->status)->toBe('reversed');
});

it('ignores a postback with no click id', function () {
    $dto = (new AdGateMediaDriver($this->network))->parsePostback(Request::create('/postback/adgatemedia', 'GET', [
        'offer_id' => '41288',
        'payout' => '3.05',
    ]));

    expect($dto)->toBeNull();
});

it('checks the shared postback token only when one is configured', function () {
    $open = new AdGateMediaDriver($this->network);

    expect($open->verifyPostback(Request::create('/postback/adgatemedia')))->toBeTrue();

    $this->network->update(['credentials' => [...$this->network->credentials, 'postback_key' => 'shared-token']]);
    $guarded = new AdGateMediaDriver($this->network->fresh());

    expect($guarded->verifyPostback(Request::create('/postback/adgatemedia', 'GET', ['key' => 'shared-token'])))->toBeTrue()
        ->and($guarded->verifyPostback(Request::create('/postback/adgatemedia', 'GET', ['key' => 'wrong'])))->toBeFalse();
});
