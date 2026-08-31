<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\KlinkDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'klink',
        'credentials' => [
            'pub_id' => '271e6dc9-d2fd-4f21-bba4-cdabc9df3ad2',
            'api_key' => 'klink-key',
        ],
    ]);

    $this->catalogue = fn () => Http::fake([
        'klink-quest.klink.finance/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/klink-offers.json')), true)
        ),
    ]);

    $this->postback = fn (array $overrides = []) => Request::create('/postback/klink', 'GET', [
        'payout' => '11.50',
        'status' => 'completed',
        'userId' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'offerId' => '19640766',
        'offerName' => 'Hungry Shredder',
        'eventId' => '12345',
        'eventName' => 'Install',
        'eventType' => 'conversion',
        'conversionId' => 'b97ab892-b724-4276-b94a-bacadbecb62f',
        'reversedConversionId' => null,
        'k1' => 'custom1',
        'ip' => '203.0.113.9',
        ...$overrides,
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new KlinkDriver($this->network))->fetchOffers(), false);

    // The paused row and the row with no offerId are not tiles anyone can click.
    expect($offers)->toHaveCount(2)
        ->and(collect($offers)->pluck('externalId')->all())->toBe(['271450', '4096']);

    expect($offers[0]->name)->toBe('RAID: Shadow Legends')
        ->and($offers[0]->payout)->toBe(144.27)
        ->and($offers[0]->description)->toBe('RAID: Shadow Legends')
        ->and($offers[0]->iconUrl)->toBe('https://d1mys92jzce605.cloudfront.net/custom_icon/67a22a987e50a-271450.jpg')
        ->and($offers[0]->countries)->toBe(['DE'])
        ->and($offers[0]->devices)->toBe(['desktop'])
        ->and($offers[0]->categories)->toBe(['Tasks', 'Gaming', 'Crypto'])
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->goals)->toBe([
            ['name' => 'Complete the tutorial', 'payout' => 0.315],
            ['name' => 'Reach level 10', 'payout' => 143.955],
        ])
        ->and($offers[0]->trackingUrl)
        ->toBe('https://klink-quest.klink.finance/redirect?pubId=271e6dc9-d2fd-4f21-bba4-cdabc9df3ad2&offerId=271450&userId={click_id}');

    expect($offers[1]->payout)->toBe(11.5)
        ->and($offers[1]->devices)->toBe(['android'])
        ->and($offers[1]->countries)->toBe(['US', 'GB'])
        ->and($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->goals)->toBe([
            ['name' => 'Install', 'payout' => 11.5],
        ]);
});

it('authenticates with the pub-id:api-key bearer token', function () {
    ($this->catalogue)();

    iterator_to_array((new KlinkDriver($this->network))->fetchOffers(), false);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://klink-quest.klink.finance/api/v1/publisher/offers')
        && $request->hasHeader('Authorization', 'Bearer 271e6dc9-d2fd-4f21-bba4-cdabc9df3ad2:klink-key')
        && $request['page'] === 1
        && $request['limit'] === 500);
});

it('walks pages until totalPages', function () {
    Http::fake([
        'klink-quest.klink.finance/*' => Http::sequence()
            ->push([
                'success' => true,
                'total' => 2,
                'currentPage' => 1,
                'totalPages' => 2,
                'data' => [[
                    'offerId' => 'page-1',
                    'name' => ['en' => 'First'],
                    'totalPayout' => 1,
                    'isActive' => true,
                    'activities' => [],
                    'countries' => ['US'],
                ]],
            ])
            ->push([
                'success' => true,
                'total' => 2,
                'currentPage' => 2,
                'totalPages' => 2,
                'data' => [[
                    'offerId' => 'page-2',
                    'name' => ['en' => 'Second'],
                    'totalPayout' => 2,
                    'isActive' => true,
                    'activities' => [],
                    'countries' => ['DE'],
                ]],
            ]),
    ]);

    $offers = iterator_to_array((new KlinkDriver($this->network))->fetchOffers(), false);

    expect(collect($offers)->pluck('externalId')->all())->toBe(['page-1', 'page-2']);
    Http::assertSentCount(2);
});

it('treats a mobile web offer as phones, not desktop', function () {
    Http::fake([
        'klink-quest.klink.finance/*' => Http::response([
            'success' => true,
            'total' => 1,
            'currentPage' => 1,
            'totalPages' => 1,
            'data' => [[
                'offerId' => 'mobile-web',
                'name' => ['en' => 'Mobile web'],
                'platform' => 'web',
                'deviceName' => 'mobile',
                'totalPayout' => 1,
                'isActive' => true,
                'activities' => [],
                'countries' => ['US'],
            ]],
        ]),
    ]);

    $offers = iterator_to_array((new KlinkDriver($this->network))->fetchOffers(), false);

    expect($offers[0]->devices)->toBe(['android', 'ios']);
});

it('counts the catalogue behind the test connection button', function () {
    ($this->catalogue)();

    $result = (new KlinkDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(4);
});

it('reports why the network refused rather than just the status code', function () {
    Http::fake([
        'klink-quest.klink.finance/*' => Http::response(['success' => false, 'message' => 'Invalid API key'], 401),
    ]);

    $result = (new KlinkDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 401')
        ->and($result->message)->toContain('Invalid API key');
});

it('treats a success:false envelope carrying http 200 as a failure', function () {
    Http::fake([
        'klink-quest.klink.finance/*' => Http::response(['success' => false, 'error' => 'Publisher not found']),
    ]);

    $driver = new KlinkDriver($this->network);

    expect($driver->testConnection()->ok)->toBeFalse()
        ->and($driver->testConnection()->message)->toContain('Publisher not found');

    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'Publisher not found');
});

it('parses a credited postback', function () {
    $dto = (new KlinkDriver($this->network))->parsePostback(($this->postback)());

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(11.5)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('19640766')
        ->and($dto->offerName)->toBe('Install')
        ->and($dto->ip)->toBe('203.0.113.9')
        ->and($dto->networkTxId)->toBe('b97ab892-b724-4276-b94a-bacadbecb62f');
});

it('holds a pending fire instead of crediting it', function () {
    $dto = (new KlinkDriver($this->network))->parsePostback(($this->postback)([
        'status' => 'pending',
    ]));

    expect($dto->status)->toBe('pending')
        ->and($dto->payout)->toBe(11.5);
});

it('parses a posted json body the same way as a get', function () {
    $request = Request::create('/postback/klink', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode([
        'payout' => '11.50',
        'status' => 'completed',
        'userId' => 'user-pub-001',
        'offerId' => '19640766',
        'eventName' => 'Install',
        'eventType' => 'conversion',
        'conversionId' => 'b97ab892-b724-4276-b94a-bacadbecb62f',
    ], JSON_THROW_ON_ERROR));

    $dto = (new KlinkDriver($this->network))->parsePostback($request);

    expect($dto->clickId)->toBe('user-pub-001')
        ->and($dto->payout)->toBe(11.5)
        ->and($dto->status)->toBe('credited');
});

it('reverses on event type, status, or sign, and keeps the credit key', function () {
    $driver = new KlinkDriver($this->network);

    $reversal = $driver->parsePostback(($this->postback)([
        'eventType' => 'chargeback',
        'status' => 'cancelled',
        'payout' => '-11.50',
        'conversionId' => 'e7f10a7b-ce56-415d-b894-6c11e070b898',
        'reversedConversionId' => 'b97ab892-b724-4276-b94a-bacadbecb62f',
    ]));

    expect($reversal->networkTxId)->toBe('b97ab892-b724-4276-b94a-bacadbecb62f')
        ->and($reversal->payout)->toBe(-11.5)
        ->and($reversal->status)->toBe('reversed');

    expect($driver->parsePostback(($this->postback)(['status' => 'cancelled']))->status)->toBe('reversed')
        ->and($driver->parsePostback(($this->postback)(['payout' => '-11.50']))->status)->toBe('reversed');
});

it('falls back to k1 when userId is missing', function () {
    $dto = (new KlinkDriver($this->network))->parsePostback(($this->postback)([
        'userId' => '',
        'k1' => 'click-from-k1',
    ]));

    expect($dto->clickId)->toBe('click-from-k1');
});

it('does not parse a postback missing the click id or the conversion id', function () {
    $driver = new KlinkDriver($this->network);

    expect($driver->parsePostback(($this->postback)(['userId' => '', 'k1' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['conversionId' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['payout' => 'free'])))->toBeNull();
});

it('puts the click id in the userId slot', function () {
    $driver = new KlinkDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://klink-quest.klink.finance/redirect?pubId=pub&offerId=4096&userId={click_id}',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://klink-quest.klink.finance/redirect?pubId=pub&offerId=4096&userId=click-uuid');

    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://klink-quest.klink.finance/redirect?pubId=pub&offerId=4096',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://klink-quest.klink.finance/redirect?pubId=pub&offerId=4096&userId=click-uuid');
});
