<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\SkylupDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'skylup',
        'credentials' => ['api_key' => 'skylup-key'],
    ]);

    $this->catalogue = fn () => Http::fake([
        'feed.skylup.swaarm-clients.com/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/skylup-offers.json')), true)
        ),
    ]);

    $this->postback = fn (array $overrides = []) => Request::create('/postback/skylup', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'payout' => '1.70',
        'txid' => 'Ne_tpNr2ZAAAAAF',
        'status' => 'APPROVED',
        'offer_id' => '34986',
        'offer_name' => "Balls and Ropes: Cut 'n Bounce",
        'event_id' => '16407',
        'event_name' => 'Complete Level 20',
        'ip' => '203.0.113.9',
        ...$overrides,
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new SkylupDriver($this->network))->fetchOffers(), false);

    expect(collect($offers)->pluck('externalId')->all())->toBe([
        '34986', '34987', '38180', '33536', '34740', '35905',
        '35159', '40131', '40135', '40134', '90005', '90006',
    ]);

    expect($offers[0]->name)->toBe("Balls and Ropes: Cut 'n Bounce")
        ->and($offers[0]->payout)->toBe(0.3375)
        ->and($offers[0]->description)->toContain("Play Balls and Ropes: Cut 'n Bounce")
        ->and($offers[0]->description)->not->toContain('<p>')
        ->and($offers[0]->description)->not->toContain('&nbsp;')
        ->and($offers[0]->instructions)->toBe('Install, Play, and Watch Ads for Reward!')
        ->and($offers[0]->iconUrl)->toBe('https://creatives.skylup.swaarm-clients.com/objects/146/1e0ae4fd-8d8a-4d33-b9ed-5e7845773b47.webp')
        ->and($offers[0]->previewUrl)->toBe('https://play.google.com/store/apps/details?id=com.PlayHallaGames.BallsNRopes&hl=en')
        ->and($offers[0]->countries)->toBe(['US'])
        ->and($offers[0]->devices)->toBe(['android'])
        ->and($offers[0]->categories)->toBe(['FREE', 'GAMING_APP', 'NO_CC', 'OFFERWALL'])
        ->and($offers[0]->conversionType)->toBe('cpa')
        ->and($offers[0]->isMultiReward)->toBeFalse()
        ->and($offers[0]->goals)->toBe([])
        ->and($offers[0]->dailyCap)->toBeNull()
        ->and($offers[0]->trackingUrl)
        ->toBe('https://skylup.trckswrm.com/click?offer_id=34986&pub_id=519&pub_click_id={click_id}');

    // Empty HTML is not a description. Offer-level payout is 0; the tile
    // price is the sum of the MONETIZATION events.
    expect($offers[1]->name)->toBe('Pop Block Puzzle')
        ->and($offers[1]->description)->toBeNull()
        ->and($offers[1]->payout)->toBe(0.105)
        ->and($offers[1]->devices)->toBe(['ios'])
        ->and($offers[1]->countries)->toBe([
            'RS', 'PT', 'BG', 'LT', 'LV', 'SA', 'BR', 'SI', 'ID', 'EC',
            'GH', 'ZA', 'CL', 'MX', 'CO', 'ET', 'VE', 'TH', 'PE', 'LB',
            'CZ', 'PH', 'VN', 'NG', 'PL', 'TR',
        ])
        ->and($offers[1]->isMultiReward)->toBeTrue()
        ->and($offers[1]->goals)->toBe([
            ['name' => 'Complete Level 20', 'payout' => 0.015],
            ['name' => 'Complete Level 70', 'payout' => 0.0375],
            ['name' => 'Complete Level 100', 'payout' => 0.0525],
        ]);

    // QUALITY events have no payout and are not goals. daily_leads is the cap.
    expect($offers[2]->externalId)->toBe('38180')
        ->and($offers[2]->payout)->toBe(0.8925)
        ->and($offers[2]->goals)->toBe([])
        ->and($offers[2]->isMultiReward)->toBeFalse()
        ->and($offers[2]->dailyCap)->toBe(20)
        ->and($offers[2]->osMinVersion)->toBe('10.0.0');

    // Default action is $0.0075; using it as the tile price would hide $0.15
    // of payable events.
    expect($offers[3]->externalId)->toBe('33536')
        ->and($offers[3]->payout)->toBe(0.15)
        ->and($offers[3]->goals)->toBe([
            ['name' => 'Reach Team level 10', 'payout' => 0.075],
            ['name' => 'Reach Team level 20', 'payout' => 0.075],
        ]);

    // WEB is not desktop. parseDevices() would hide this from phones.
    expect($offers[4]->externalId)->toBe('34740')
        ->and($offers[4]->devices)->toBe([])
        ->and($offers[4]->payout)->toBe(9.75)
        ->and($offers[4]->isMultiReward)->toBeFalse();

    expect($offers[5]->externalId)->toBe('35905')
        ->and($offers[5]->devices)->toBe(['desktop'])
        ->and($offers[5]->osMinVersion)->toBe('149.0.0')
        ->and($offers[6]->externalId)->toBe('35159')
        ->and($offers[6]->devices)->toBe(['desktop'])
        ->and($offers[6]->countries)->toBe(['PT', 'ES']);

    // MULTI_REWARD with one event is not multi-reward. The type falls to cpa.
    expect($offers[7]->externalId)->toBe('40131')
        ->and($offers[7]->payout)->toBe(3.6)
        ->and($offers[7]->conversionType)->toBe('cpa')
        ->and($offers[7]->isMultiReward)->toBeFalse()
        ->and($offers[7]->devices)->toBe([]);

    // MULTI_REWARD with no events is a single-price offer.
    expect($offers[8]->externalId)->toBe('40135')
        ->and($offers[8]->payout)->toBe(13.5)
        ->and($offers[8]->isMultiReward)->toBeFalse()
        ->and($offers[8]->conversionType)->toBe('cpa');

    expect($offers[9]->externalId)->toBe('40134')
        ->and($offers[9]->conversionType)->toBe('cpi')
        ->and($offers[9]->payout)->toBe(3.9225)
        ->and($offers[9]->countries)->toBe(['IT']);

    // UK is not ISO-2. Stored as-is it targets a country nothing geolocates to.
    expect($offers[10]->externalId)->toBe('90005')
        ->and($offers[10]->countries)->toBe(['GB', 'US']);

    // Two hundred ISO codes is worldwide, not a targeting list.
    expect($offers[11]->externalId)->toBe('90006')
        ->and($offers[11]->countries)->toBe([]);
});

it('authenticates with api_key on v1.3, in one unfiltered call', function () {
    ($this->catalogue)();

    iterator_to_array((new SkylupDriver($this->network))->fetchOffers(), false);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://feed.skylup.swaarm-clients.com/feed/v1.3/ads')
        && $request['api_key'] === 'skylup-key'
        && ! isset($request['apiKey']));
});

it('reports the plain-text 403 rather than just the status code', function () {
    Http::fake(['feed.skylup.swaarm-clients.com/*' => Http::response('Publisher does not exist.', 403)]);

    $result = (new SkylupDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 403')
        ->and($result->message)->toContain('Publisher does not exist.');
});

it('counts the catalogue behind the test connection button', function () {
    ($this->catalogue)();

    $result = (new SkylupDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(19);
});

it('treats a 200 with no ads array as a failure', function () {
    Http::fake([
        'feed.skylup.swaarm-clients.com/*' => Http::response(['publisher' => ['id' => '519']]),
    ]);

    $driver = new SkylupDriver($this->network);

    expect($driver->testConnection()->ok)->toBeFalse();

    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class);
});

it('parses a credited postback in dollars', function () {
    $dto = (new SkylupDriver($this->network))->parsePostback(($this->postback)());

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(1.7)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('34986')
        ->and($dto->offerName)->toBe('Complete Level 20')
        ->and($dto->ip)->toBe('203.0.113.9')
        ->and($dto->networkTxId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f:34986:16407')
        ->and($dto->endUserId)->toBeNull();
});

it('keys the default event separately from a named one', function () {
    $driver = new SkylupDriver($this->network);

    $install = $driver->parsePostback(($this->postback)([
        'event_id' => '',
        'event_name' => '',
        'offer_name' => 'Balls and Ropes',
    ]));
    $level = $driver->parsePostback(($this->postback)());

    expect($install->networkTxId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f:34986:default')
        ->and($install->offerName)->toBe('Balls and Ropes')
        ->and($level->networkTxId)->not->toBe($install->networkTxId);
});

it('charges back on REJECTED, keeping the key its credit produced', function () {
    $driver = new SkylupDriver($this->network);

    $credit = $driver->parsePostback(($this->postback)());
    $reversal = $driver->parsePostback(($this->postback)([
        'status' => 'REJECTED',
        'txid' => 'a-new-postback-id',
    ]));

    expect($reversal->networkTxId)->toBe($credit->networkTxId)
        ->and($reversal->payout)->toBe(-1.7)
        ->and($reversal->status)->toBe('reversed');
});

it('reverses a negative payout even when status is still APPROVED', function () {
    $reversal = (new SkylupDriver($this->network))->parsePostback(($this->postback)([
        'payout' => '-1.70',
        'status' => 'APPROVED',
    ]));

    expect($reversal->status)->toBe('reversed')
        ->and($reversal->payout)->toBe(-1.7)
        ->and($reversal->networkTxId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f:34986:16407');
});

it('holds a PENDING fire without crediting it', function () {
    $dto = (new SkylupDriver($this->network))->parsePostback(($this->postback)([
        'status' => 'PENDING',
    ]));

    expect($dto->status)->toBe('pending')
        ->and($dto->payout)->toBe(1.7);
});

it('does not parse a postback missing the click id or the payout', function () {
    $driver = new SkylupDriver($this->network);

    expect($driver->parsePostback(($this->postback)(['click_id' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['payout' => 'free'])))->toBeNull();
});

it('leaves the allowlist as the only guard', function () {
    expect((new SkylupDriver($this->network))->verifyPostback(($this->postback)()))->toBeTrue();
});

it('puts the click id in pub_click_id', function () {
    $driver = new SkylupDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://skylup.trckswrm.com/click?offer_id=34986&pub_id=519&pub_click_id={click_id}',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://skylup.trckswrm.com/click?offer_id=34986&pub_id=519&pub_click_id=click-uuid');

    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://skylup.trckswrm.com/click?offer_id=34986&pub_id=519',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://skylup.trckswrm.com/click?offer_id=34986&pub_id=519&pub_click_id=click-uuid');
});
