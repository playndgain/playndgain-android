<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\AyetStudiosDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'ayetstudios',
        'credentials' => ['adslot_id' => '4471', 'api_key' => 'ayet-key'],
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    Http::fake([
        'ayetstudios.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/ayetstudios-offers.json'))),
    ]);

    $offers = iterator_to_array((new AyetStudiosDriver($this->network))->fetchOffers(), false);

    expect($offers)->toHaveCount(2);

    expect($offers[0]->externalId)->toBe('918204')
        // Every task completed is what the offer is worth: 2.10 + 4.40 + 7.50.
        ->and($offers[0]->payout)->toBe(14.0)
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->goals)->toBe([
            ['name' => 'Reach village 5', 'payout' => 2.1],
            ['name' => 'Reach village 10', 'payout' => 4.4],
            ['name' => 'Reach village 15', 'payout' => 7.5],
        ])
        ->and($offers[0]->description)->toBe('Install Coin Master and reach village 15.')
        ->and($offers[0]->instructions)->toBe("Reach village 15 within 14 days.\nSpin, raid and build your way to village 15.")
        ->and($offers[0]->iconUrl)->toBe('https://cdn.ayetstudios.com/offers/918204.png')
        ->and($offers[0]->countries)->toBe(['US', 'CA'])
        ->and($offers[0]->devices)->toBe(['android'])
        ->and($offers[0]->categories)->toBe(['Games', 'Casual'])
        ->and($offers[0]->conversionType)->toBe('cpi')
        // The path placeholder is Ayet's user slot on this one; `custom_1` is
        // ours and is appended because the link has nowhere else to put it.
        ->and($offers[0]->trackingUrl)->toBe('https://www.ayetstudios.com/offers/click/918204/{click_id}?custom_1={click_id}');

    expect($offers[1]->payout)->toBe(12.5)
        ->and($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->goals)->toBe([])
        // Blank description, so the introduction is the only blurb there is.
        ->and($offers[1]->description)->toBe('Order your first box and get cooking.')
        ->and($offers[1]->iconUrl)->toBeNull()
        ->and($offers[1]->countries)->toBe([])
        // Single-platform offers carry `platform` instead of `platforms`.
        ->and($offers[1]->devices)->toBe(['ios'])
        // No tags, so how it converts and where Ayet files it stand in.
        ->and($offers[1]->categories)->toBe(['purchase', 'Shopping'])
        ->and($offers[1]->conversionType)->toBe('purchase')
        ->and($offers[1]->trackingUrl)->toBe('https://www.ayetstudios.com/offers/click/918377?adslot=4471&external_identifier={click_id}&custom_1={click_id}');
});

it('calls the adslot endpoint with the api key', function () {
    Http::fake([
        'ayetstudios.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/Networks/ayetstudios-offers.json'))),
    ]);

    iterator_to_array((new AyetStudiosDriver($this->network))->fetchOffers(), false);

    Http::assertSent(function ($request) {
        expect($request->url())->toContain('/offers/get/4471');

        return str_contains($request->url(), 'apiKey=ayet-key');
    });
});

it('fails the connection test on an http error', function () {
    Http::fake(['ayetstudios.com/*' => Http::response('nope', 503)]);

    $result = (new AyetStudiosDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 503');
});

it('treats a non-success status carrying http 200 as a failure', function () {
    // Ayet rejects a bad key inside the envelope, with the status line saying
    // the call went fine. Read as an empty catalogue this deactivates every
    // Ayet offer we have, and the connection test goes green on a typo.
    Http::fake([
        'ayetstudios.com/*' => Http::response(['status' => 'error', 'message' => 'invalid api key', 'offers' => []]),
    ]);

    $driver = new AyetStudiosDriver($this->network);

    expect($driver->testConnection()->ok)->toBeFalse();

    // The thrown message is what lands in `networks.last_sync_error` and on the
    // admin health page, so it carries Ayet's sentence rather than the status
    // word every one of their failures shares.
    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'invalid api key');
});

it('parses a credited conversion callback', function () {
    $dto = (new AyetStudiosDriver($this->network))->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'callback_type' => 'conversion',
        'transaction_id' => '55512345',
        'is_chargeback' => '0',
        'payout_usd' => '4.40',
        'custom_1' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'external_identifier' => 'ayet-user-77',
        'offer_id' => '918204',
        'offer_name' => 'Coin Master - Reach Village 15',
        'ip' => '203.0.113.9',
    ]));

    expect($dto->networkTxId)->toBe('55512345')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(4.4)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('918204')
        ->and($dto->offerName)->toBe('Coin Master - Reach Village 15')
        ->and($dto->ip)->toBe('203.0.113.9')
        // Neither slot holds an end user of ours: `external_identifier` is the
        // click id we sent, and Ayet's own account id is not the identity the
        // ban list or the fraud rules are keyed on. The click supplies it.
        ->and($dto->endUserId)->toBeNull();
});

it('matches a chargeback to the key its own credit was written under', function () {
    $driver = new AyetStudiosDriver($this->network);
    $payload = ['custom_1' => 'click-uuid', 'offer_id' => '918204'];

    $credit = $driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        ...$payload,
        'callback_type' => 'conversion',
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
    ]));

    // Ayet prepends `r-` to the transaction id on a chargeback. Carried through
    // as-is it never meets the credit it reverses: `ConversionProcessor::
    // reverse()` matches `network_txid` exactly, so every Ayet chargeback would
    // fail with "no conversion for network_txid" and the money would stay paid.
    $chargeback = $driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        ...$payload,
        'callback_type' => 'chargeback',
        'transaction_id' => 'r-55512345',
        'is_chargeback' => '1',
        'chargeback_reason' => 'advertiser rejected',
        'payout_usd' => '-4.40',
    ]));

    expect($chargeback->networkTxId)->toBe($credit->networkTxId)
        ->and($chargeback->networkTxId)->toBe('55512345')
        ->and($chargeback->payout)->toBe(-4.4)
        ->and($chargeback->status)->toBe('reversed');
});

it('treats is_chargeback on its own as a reversal', function () {
    // Either flag is believed alone. A pasted template that dropped
    // `callback_type` would otherwise credit the publisher a second time for
    // money the advertiser has just taken back.
    $dto = (new AyetStudiosDriver($this->network))->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => 'r-55512345',
        'is_chargeback' => '1',
        'payout_usd' => '4.40',
        'custom_1' => 'click-uuid',
    ]));

    expect($dto->status)->toBe('reversed')
        ->and($dto->payout)->toBe(-4.4)
        ->and($dto->networkTxId)->toBe('55512345');
});

it('does not parse a postback with no transaction id or no amount', function () {
    $driver = new AyetStudiosDriver($this->network);

    // The transaction id is the idempotency key; without it a retry cannot be
    // told apart from a second conversion.
    expect($driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'custom_1' => 'click-uuid',
        'payout_usd' => '4.40',
    ])))->toBeNull();

    expect($driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '55512345',
        'payout_usd' => '',
    ])))->toBeNull();
});

it('still parses a postback whose click id never came back', function () {
    // Not a rejection. A 422 here buys twelve retries of the same unparseable
    // payload over the next hour; a DTO with a null click is logged once, at
    // 200, where an admin can attach it to the click by hand.
    $dto = (new AyetStudiosDriver($this->network))->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
    ]));

    expect($dto)->not->toBeNull()
        ->and($dto->clickId)->toBeNull();
});

it('reads the click id out of external_identifier when custom_1 is absent', function () {
    // Clicks made before `custom_1` became the carrier are still in flight —
    // the Coin Master offer gives the user fourteen days — and they come back
    // with the click id in the slot it used to occupy.
    $dto = (new AyetStudiosDriver($this->network))->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
        'external_identifier' => 'legacy-click-uuid',
    ]));

    expect($dto->clickId)->toBe('legacy-click-uuid');
});

it('accepts a postback signed with the publisher api key', function () {
    $this->network->update(['credentials' => [
        'adslot_id' => '4471',
        'api_key' => 'ayet-key',
        'publisher_api_key' => 'publisher-key',
    ]]);

    $params = [
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
        'custom_1' => 'click-uuid',
        'callback_type' => 'conversion',
    ];

    // The recipe is spelled out here rather than borrowed from the driver, so
    // that changing the driver's interpretation has to change this line too.
    $signed = $params;
    ksort($signed, SORT_STRING);
    $hash = hash_hmac('sha256', http_build_query($signed), 'publisher-key');

    $request = Request::create('/postback/ayetstudios', 'GET', $params, [], [], [
        'HTTP_X_AYETSTUDIOS_SECURITY_HASH' => $hash,
    ]);

    expect((new AyetStudiosDriver($this->network))->verifyPostback($request))->toBeTrue();
});

it('rejects a postback whose hash does not match', function () {
    $this->network->update(['credentials' => [
        'adslot_id' => '4471',
        'api_key' => 'ayet-key',
        'publisher_api_key' => 'publisher-key',
    ]]);

    $request = Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
    ], [], [], ['HTTP_X_AYETSTUDIOS_SECURITY_HASH' => str_repeat('0', 64)]);

    expect((new AyetStudiosDriver($this->network))->verifyPostback($request))->toBeFalse();

    // An unsigned request against a configured key is the same rejection. The
    // header is the only place the hash can arrive.
    expect((new AyetStudiosDriver($this->network))->verifyPostback(
        Request::create('/postback/ayetstudios', 'GET', ['transaction_id' => '55512345'])
    ))->toBeFalse();
});

it('leaves the allowlist as the only guard when no publisher key is configured', function () {
    // The publisher API key is account-level and optional, and a live Ayet
    // integration can be syncing and earning long before anyone finds it.
    // Failing closed would take a working network off the air; the source-IP
    // allowlist — which rejects when empty — is the floor underneath.
    expect((new AyetStudiosDriver($this->network))->verifyPostback(Request::create('/postback/ayetstudios')))->toBeTrue();
});

it('puts the click id in custom_1 and fills Ayet\'s user slot as well', function () {
    $driver = new AyetStudiosDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://www.ayetstudios.com/offers/click/918204/{click_id}?custom_1={click_id}',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://www.ayetstudios.com/offers/click/918204/click-uuid?custom_1=click-uuid');

    // A link with nowhere to put the id is never handed over untouched: an
    // untracked click is a conversion nobody can be paid for.
    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://www.ayetstudios.com/offers/click/918377?adslot=4471',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://www.ayetstudios.com/offers/click/918377?adslot=4471&custom_1=click-uuid');
});

it('round-trips the click id from the tracking link back out of a postback', function () {
    $driver = new AyetStudiosDriver($this->network);

    $offer = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://www.ayetstudios.com/offers/click/918377?adslot=4471&external_identifier=[USER_ID]',
    ]);

    $clickId = '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f';

    // The link is taken apart rather than string-matched. If the parameter
    // buildTrackingUrl() writes ever stops being the one parsePostback() reads,
    // a string assertion on the URL still passes while every conversion in
    // production goes unattributed with nothing logged.
    parse_str((string) parse_url($driver->buildTrackingUrl($offer, $clickId), PHP_URL_QUERY), $params);

    $dto = $driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
        // Ayet echoes back what the click carried.
        ...$params,
        // Except this, deliberately: the fallback in parsePostback() must not
        // be able to rescue a carrier mismatch and hide it from this test.
        'external_identifier' => 'ayet-user-77',
    ]));

    expect($dto->clickId)->toBe($clickId);
});

it('reports the reason Ayet gave, not just the word error', function () {
    // The real payload from a live adslot misconfiguration. `status` is the
    // literal string "error" on every failure, so surfacing it tells the admin
    // nothing; `errorMessage` is the sentence that names the actual problem and
    // `errorCode` is what their account manager asks for.
    Http::fake([
        'www.ayetstudios.com/*' => Http::response([
            'status' => 'error',
            'errorCode' => 103,
            'errorMessage' => 'Unknown adslot 28833, invalid adslot type or adslot api key incorrect.',
        ]),
    ]);

    $result = (new AyetStudiosDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('103')
        ->and($result->message)->toContain('Unknown adslot 28833')
        // The word on its own was the entire message before this test existed.
        ->and($result->message)->not->toBe('Ayet Studios rejected the request: error');
});

it('falls back to the status when Ayet explains nothing', function () {
    Http::fake([
        'www.ayetstudios.com/*' => Http::response(['status' => 'error']),
    ]);

    expect((new AyetStudiosDriver($this->network))->testConnection()->message)->toContain('error');
});
