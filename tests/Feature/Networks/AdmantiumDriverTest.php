<?php

use App\Models\Network;
use App\Networks\Drivers\AdmantiumDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'admantium',
        'credentials' => ['appid' => '9931'],
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    Http::fake([
        'admantum.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/admantium-offers.json'))),
    ]);

    $offers = iterator_to_array((new AdmantiumDriver($this->network))->fetchOffers(), false);

    expect($offers)->toHaveCount(2);

    expect($offers[0]->externalId)->toBe('5521')
        ->and($offers[0]->name)->toBe('Solitaire Cash - Win a Cash Game')
        ->and($offers[0]->payout)->toBe(12.4)
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->countries)->toBe(['US'])
        // iphone and ipad collapse to one device vocabulary entry.
        ->and($offers[0]->devices)->toBe(['ios'])
        ->and($offers[0]->instructions)->toContain('iOS 14 or newer.')
        // The placeholder uid the catalogue was fetched with becomes our macro.
        ->and($offers[0]->trackingUrl)->toBe('https://admantum.com/click?appid=9931&offer=5521&uid={click_id}');

    expect($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->instructions)->toBeNull()
        ->and($offers[1]->devices)->toBe([])
        // A link with no uid slot still gets one — an untracked click is an
        // unpaid conversion.
        ->and($offers[1]->trackingUrl)->toContain('&uid={click_id}');
});

it('fetches the catalogue for the placeholder user', function () {
    Http::fake([
        'admantum.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/admantium-offers.json'))),
    ]);

    iterator_to_array((new AdmantiumDriver($this->network))->fetchOffers(), false);

    Http::assertSent(fn ($request) => $request['appid'] === '9931' && $request['uid'] === '1');
});

it('parses a credited postback', function () {
    $dto = (new AdmantiumDriver($this->network))->parsePostback(Request::create('/postback/admantium', 'GET', [
        'transaction_id' => 'ADM-771',
        'offer_id' => '5521',
        'uid' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '12.40',
        'status' => '1',
    ]));

    expect($dto->networkTxId)->toBe('ADM-771')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(12.4)
        ->and($dto->status)->toBe('credited');
});

it('reads status 2 as a reversal', function () {
    $dto = (new AdmantiumDriver($this->network))->parsePostback(Request::create('/postback/admantium', 'GET', [
        'transaction_id' => 'ADM-771',
        'uid' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '12.40',
        'status' => '2',
    ]));

    expect($dto->payout)->toBe(-12.4)
        ->and($dto->status)->toBe('reversed');
});

it('checks the postback secret only when one is configured', function () {
    expect((new AdmantiumDriver($this->network))->verifyPostback(Request::create('/postback/admantium')))->toBeTrue();

    $this->network->update(['credentials' => ['appid' => '9931', 'postback_secret' => 'adm-secret']]);
    $guarded = new AdmantiumDriver($this->network->fresh());

    expect($guarded->verifyPostback(Request::create('/postback/admantium', 'GET', ['secret' => 'adm-secret'])))->toBeTrue()
        ->and($guarded->verifyPostback(Request::create('/postback/admantium', 'GET', ['secret' => 'wrong'])))->toBeFalse();
});
