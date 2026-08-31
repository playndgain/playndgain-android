<?php

use App\Models\Network;
use App\Networks\Drivers\AdscendMediaDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'adscendmedia',
        'credentials' => ['pub_id' => '8812', 'api_key' => 'adscend-key'],
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    Http::fake([
        'api.adscendmedia.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/adscendmedia-offers.json'))),
    ]);

    $offers = iterator_to_array((new AdscendMediaDriver($this->network))->fetchOffers(), false);

    expect($offers)->toHaveCount(2);

    expect($offers[0]->externalId)->toBe('771204')
        ->and($offers[0]->payout)->toBe(4.8)
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->countries)->toBe(['US', 'GB'])
        // target_system 40 is Android-only.
        ->and($offers[0]->devices)->toBe(['android'])
        ->and($offers[0]->categories)->toBe(['Games', 'Casual'])
        ->and($offers[0]->iconUrl)->toBe('https://cdn.adscendmedia.com/creatives/771204.png')
        ->and($offers[0]->trackingUrl)->toContain('&sub1={click_id}');

    expect($offers[1]->devices)->toBe(['desktop'])
        ->and($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->categories)->toBe(['Email Submit'])
        ->and($offers[1]->iconUrl)->toBeNull()
        // No query string on the click_url, so the sub id opens one.
        ->and($offers[1]->trackingUrl)->toContain('?sub1={click_id}');
});

it('authenticates with the publisher id and key', function () {
    Http::fake([
        'api.adscendmedia.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/adscendmedia-offers.json'))),
    ]);

    iterator_to_array((new AdscendMediaDriver($this->network))->fetchOffers(), false);

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/v1/publisher/8812/offers.json');

        return $request->hasHeader('Authorization', 'Basic '.base64_encode('8812:adscend-key'));
    });
});

it('parses a credited postback', function () {
    $dto = (new AdscendMediaDriver($this->network))->parsePostback(Request::create('/postback/adscendmedia', 'GET', [
        'transaction_id' => 'ASC-5521',
        'offer_id' => '771204',
        'sub1' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '4.80',
        'member_id' => 'end-user-42',
    ]));

    expect($dto->networkTxId)->toBe('ASC-5521')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(4.8)
        ->and($dto->status)->toBe('credited')
        ->and($dto->endUserId)->toBe('end-user-42');
});

it('reads a negative payout as a reversal', function () {
    $dto = (new AdscendMediaDriver($this->network))->parsePostback(Request::create('/postback/adscendmedia', 'GET', [
        'transaction_id' => 'ASC-5521',
        'sub1' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '-4.80',
    ]));

    expect($dto->payout)->toBe(-4.8)
        ->and($dto->status)->toBe('reversed');
});

it('checks the shared postback token only when one is configured', function () {
    expect((new AdscendMediaDriver($this->network))->verifyPostback(Request::create('/postback/adscendmedia')))->toBeTrue();

    $this->network->update(['credentials' => [...$this->network->credentials, 'postback_key' => 'shared-token']]);
    $guarded = new AdscendMediaDriver($this->network->fresh());

    expect($guarded->verifyPostback(Request::create('/postback/adscendmedia', 'GET', ['key' => 'shared-token'])))->toBeTrue()
        ->and($guarded->verifyPostback(Request::create('/postback/adscendmedia', 'GET', ['key' => 'wrong'])))->toBeFalse();
});
