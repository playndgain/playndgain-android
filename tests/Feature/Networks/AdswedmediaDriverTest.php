<?php

use App\Models\Network;
use App\Networks\Drivers\AdswedmediaDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'adswedmedia',
        'credentials' => ['site_key' => 'site-abc', 'site_secret' => 'swed-secret'],
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    Http::fake([
        'adswedmedia.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/adswedmedia-offers.json'))),
    ]);

    $offers = iterator_to_array((new AdswedmediaDriver($this->network))->fetchOffers(), false);

    expect($offers)->toHaveCount(2);

    expect($offers[0]->externalId)->toBe('3312')
        ->and($offers[0]->payout)->toBe(5.5)
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->countries)->toBe(['US', 'CA'])
        ->and($offers[0]->devices)->toBe(['android'])
        // The catalogue carries a bare filename, not a URL.
        ->and($offers[0]->iconUrl)->toBe('https://adswedmedia.com/asset/images/offers/3312.png')
        // Both template placeholders are filled: the site key identifies us,
        // the user slot carries the click.
        ->and($offers[0]->trackingUrl)->toBe('https://adswedmedia.com/click/3312?key=site-abc&user_id={click_id}');

    expect($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->iconUrl)->toBeNull()
        // No user slot in the template, so one is added.
        ->and($offers[1]->trackingUrl)->toBe('https://adswedmedia.com/click/3399?key=site-abc&user_id={click_id}');
});

it('authenticates the catalogue request', function () {
    Http::fake([
        'adswedmedia.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/adswedmedia-offers.json'))),
    ]);

    iterator_to_array((new AdswedmediaDriver($this->network))->fetchOffers(), false);

    Http::assertSent(fn ($request) => $request['site_key'] === 'site-abc' && $request['site_secret'] === 'swed-secret');
});

it('parses a credited postback', function () {
    $dto = (new AdswedmediaDriver($this->network))->parsePostback(Request::create('/postback/adswedmedia', 'GET', [
        'transaction_id' => 'SWD-9001',
        'offer_id' => '3312',
        'user_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '5.50',
    ]));

    expect($dto->networkTxId)->toBe('SWD-9001')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(5.5)
        ->and($dto->status)->toBe('credited');
});

it('fails a postback that carries the wrong secret', function () {
    $driver = new AdswedmediaDriver($this->network);

    expect($driver->verifyPostback(Request::create('/postback/adswedmedia', 'GET', ['secret' => 'swed-secret'])))->toBeTrue()
        ->and($driver->verifyPostback(Request::create('/postback/adswedmedia', 'GET', ['secret' => 'wrong'])))->toBeFalse()
        ->and($driver->verifyPostback(Request::create('/postback/adswedmedia')))->toBeFalse();
});

it('fails closed when the secret is missing', function () {
    $this->network->update(['credentials' => ['site_key' => 'site-abc']]);

    expect((new AdswedmediaDriver($this->network->fresh()))->verifyPostback(
        Request::create('/postback/adswedmedia', 'GET', ['secret' => ''])
    ))->toBeFalse();
});
