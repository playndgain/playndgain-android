<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\NotikDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'notik',
        'credentials' => [
            'api_key' => 'notik-api-key',
            'pub_id' => '4821',
            'app_id' => '77',
            'postback_secret' => 'notik-postback-secret',
        ],
    ]);

    $this->postback = [
        'txn_id' => 'NTK-5512399',
        's1' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'offer_id' => '88213',
        'offer_name' => 'Royal Match - Reach Level 120',
        'user_id' => 'end-user-42',
        'payout' => '6.2500',
        'conversion_ip' => '41.238.10.4',
    ];

    $this->catalogue = fn () => Http::fake([
        'notik.me/api/v2/get-offers/all?page=2*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/notik-offers-page2.json'))),
        'notik.me/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/notik-offers.json'))),
    ]);
});

function notikSignedRequest(array $params, string $secret): Request
{
    $unsigned = Request::create('http://localhost/postback/notik', 'GET', $params);
    $hash = hash_hmac('sha1', $unsigned->getSchemeAndHttpHost().$unsigned->getRequestUri(), $secret);

    return Request::create('http://localhost/postback/notik', 'GET', [...$params, 'hash' => $hash]);
}

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new NotikDriver($this->network))->fetchOffers(), false);

    // Two priced rows on page one, one on page two. The points-priced row is
    // dropped — its payout is not dollars and nothing here knows the rate.
    expect($offers)->toHaveCount(3);

    expect($offers[0]->externalId)->toBe('88213')
        ->and($offers[0]->payout)->toBe(6.25)
        ->and($offers[0]->countries)->toBe(['US', 'CA'])
        ->and($offers[0]->devices)->toBe(['android'])
        ->and($offers[0]->categories)->toBe(['games', 'casual'])
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->instructions)->toContain('New users only.')
        ->and($offers[0]->trackingUrl)->toContain('s1={click_id}');

    // An empty country list is worldwide, not a broken row.
    expect($offers[1]->countries)->toBe([])
        ->and($offers[1]->devices)->toBe(['android', 'ios', 'desktop'])
        ->and($offers[1]->isMultiReward)->toBeFalse();

    expect(collect($offers)->pluck('externalId')->all())->not->toContain('88500');
});

it('follows the pagination it is handed', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new NotikDriver($this->network))->fetchOffers(), false);

    expect($offers[2]->externalId)->toBe('88611')
        ->and($offers[2]->countries)->toBe(['GB']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'page=2'));
});

it('authenticates every page with the key in the query string', function () {
    ($this->catalogue)();

    iterator_to_array((new NotikDriver($this->network))->fetchOffers(), false);

    // Notik's next_page_url carries the cursor and nothing else. Both hops must
    // still be signed, or page two is a 401 and the catalogue ends early.
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api_key=notik-api-key')
        && str_contains($request->url(), 'pub_id=4821')
        && str_contains($request->url(), 'app_id=77')
        && str_contains($request->url(), 'page=2'));
});

it('parses a credited postback', function () {
    $dto = (new NotikDriver($this->network))
        ->parsePostback(Request::create('/postback/notik', 'GET', $this->postback));

    expect($dto->networkTxId)->toBe('NTK-5512399')
        ->and($dto->clickId)->toBe($this->postback['s1'])
        ->and($dto->payout)->toBe(6.25)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('88213')
        ->and($dto->endUserId)->toBeNull()
        ->and($dto->ip)->toBe('41.238.10.4');
});

it('parses a chargeback against the original transaction id', function () {
    $dto = (new NotikDriver($this->network))->parsePostback(Request::create('/postback/notik', 'GET', [
        ...$this->postback,
        'txn_id' => 'NTK-CHARGEBACK-1',
        'rewarded_txn_id' => 'NTK-5512399',
        'payout' => '-6.2500',
    ]));

    expect($dto->status)->toBe('reversed')
        ->and($dto->networkTxId)->toBe('NTK-5512399')
        ->and($dto->payout)->toBe(-6.25);
});

it('accepts a hash of the callback url without the hash parameter', function () {
    $driver = new NotikDriver($this->network);
    $valid = notikSignedRequest($this->postback, 'notik-postback-secret');
    $tampered = Request::create('http://localhost/postback/notik', 'GET', [
        ...$this->postback,
        'payout' => '620.00',
        'hash' => $valid->input('hash'),
    ]);

    expect($driver->verifyPostback($valid))->toBeTrue()
        ->and($driver->verifyPostback($tampered))->toBeFalse();
});

it('fails closed when the postback secret is missing', function () {
    $this->network->update(['credentials' => ['api_key' => 'notik-api-key', 'pub_id' => '4821', 'app_id' => '77']]);

    expect((new NotikDriver($this->network->fresh()))->verifyPostback(
        notikSignedRequest($this->postback, 'notik-postback-secret')
    ))->toBeFalse();
});

it('puts the click id in the user_id slot and appends s1', function () {
    $driver = new NotikDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'http://127.0.0.1:8000/api-redirect?token=eyJ...&user_id=$9uz_Mpe3O7Nw1S_[user_id]',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('http://127.0.0.1:8000/api-redirect?token=eyJ...&user_id=$9uz_Mpe3O7Nw1S_click-uuid&s1=click-uuid');

    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://notik.me/click?oid=88213&pubid=4821',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://notik.me/click?oid=88213&pubid=4821&s1=click-uuid');

    $withCurlyBraces = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://notik.me/click?oid=88213&pubid=4821&user_id=PREFIX_{user_id}',
    ]);

    expect($driver->buildTrackingUrl($withCurlyBraces, 'click-uuid'))
        ->toBe('https://notik.me/click?oid=88213&pubid=4821&user_id=PREFIX_click-uuid&s1=click-uuid');
});
