<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\HangMyAdsDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'hangmyads',
        'credentials' => ['api_id' => '9912', 'api_token' => 'hma-token'],
    ]);

    // Page two comes back empty, which is what ends the loop. The page number
    // rides in the POST body, so the pages are a sequence rather than two URLs.
    $this->catalogue = fn () => Http::fake([
        'api.hangmytracking.com/*' => Http::sequence()
            ->push(json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/hangmyads-offers.json')), true))
            ->push(['status' => 'OK', 'data' => ['total_records' => 5, 'offers' => []], 'errors' => 0, 'errorMessages' => []]),
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new HangMyAdsDriver($this->network))->fetchOffers(), false);

    // Three of the five rows are not tiles anyone can click: one has no
    // tracking link because we are not approved for it, one is paused, and one
    // is priced in euros.
    expect($offers)->toHaveCount(2)
        ->and(collect($offers)->pluck('externalId')->all())->toBe(['85503', '85610']);

    expect($offers[0]->name)->toBe('US 8129 CPI/iOS/Direct/Utilities - Sekai: Create, Remix and Play')
        // 70 cents, not 70 dollars.
        ->and($offers[0]->payout)->toBe(0.7)
        ->and($offers[0]->description)->toBe('Run the App Two Days in a Row (New Users Only)')
        ->and($offers[0]->instructions)->toBe('Requires iOS 15.0 or later')
        ->and($offers[0]->iconUrl)->toBe('https://banners.hangmyads.com/files/uploads/Off_A_110182.jpg')
        ->and($offers[0]->previewUrl)->toBe('https://apps.apple.com/us/app/sekai-create-remix-play/id6504506653')
        ->and($offers[0]->countries)->toBe(['US'])
        // `os` says iOS; `devices: "All"` says nothing, which is correct.
        ->and($offers[0]->devices)->toBe(['ios'])
        ->and($offers[0]->categories)->toBe(['CPI', 'Utilities', 'Direct', 'iOS'])
        ->and($offers[0]->conversionType)->toBe('cpi')
        // "None" is not a minimum version, and "No Cap" is not a cap.
        ->and($offers[0]->osMinVersion)->toBeNull()
        ->and($offers[0]->dailyCap)->toBeNull()
        ->and($offers[0]->isMultiReward)->toBeFalse()
        // The click id slot is filled and the placeholders we cannot fill are
        // emptied rather than sent as the words REPLACE_WITH_GAID.
        ->and($offers[0]->trackingUrl)->toBe('https://tracking.hangmytracking.com/click.php?oid=85503&aid=9912&subid={click_id}&type=&google_aid=&ios_ifa=');

    expect($offers[1]->payout)->toBe(2.5)
        ->and($offers[1]->osMinVersion)->toBe('8.0')
        ->and($offers[1]->dailyCap)->toBe(150)
        ->and($offers[1]->countries)->toBe(['GB', 'DE'])
        ->and($offers[1]->devices)->toBe(['android'])
        ->and($offers[1]->instructions)->toBeNull()
        ->and($offers[1]->iconUrl)->toBeNull()
        ->and($offers[1]->isMultiReward)->toBeTrue()
        // Goals are keyed by name and priced in cents like everything else.
        ->and($offers[1]->goals)->toBe([
            ['name' => 'completed_registration', 'payout' => 2.5],
            ['name' => 'first deposit', 'payout' => 15.0],
        ])
        // No subid slot in this link, so one is opened for it.
        ->and($offers[1]->trackingUrl)->toBe('https://tracking.hangmytracking.com/click.php?oid=85610&aid=9912&subid={click_id}');
});

it('posts the method and the token pair, and stops on an empty page', function () {
    ($this->catalogue)();

    iterator_to_array((new HangMyAdsDriver($this->network))->fetchOffers(), false);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['method'] === 'getOffers'
        && $request['apiID'] === '9912'
        && $request['apiToken'] === 'hma-token');
});

it('fails the connection test on an http error', function () {
    Http::fake(['api.hangmytracking.com/*' => Http::response('nope', 502)]);

    $result = (new HangMyAdsDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 502');
});

it('reports the whole catalogue size rather than the page it asked for', function () {
    ($this->catalogue)();

    $result = (new HangMyAdsDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(5);
});

it('treats a NOK envelope carrying http 200 as a failure', function () {
    // A bad token and a caller whose IP is not on their allowlist both arrive
    // this way. Read as an empty catalogue it deactivates every HangMyAds offer
    // we have, and the connection test goes green on a typo.
    Http::fake([
        'api.hangmytracking.com/*' => Http::response([
            'status' => 'NOK',
            'data' => [],
            'errors' => 1,
            'errorMessages' => ['Invalid API credentials'],
        ]),
    ]);

    $driver = new HangMyAdsDriver($this->network);
    $result = $driver->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Invalid API credentials');

    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'Invalid API credentials');
});

it('parses a credited postback', function () {
    $dto = (new HangMyAdsDriver($this->network))->parsePostback(Request::create('/postback/hangmyads', 'GET', [
        'subid' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'offerid' => '85610',
        'goalname' => 'first deposit',
        'pay' => '15.00',
        'convid' => 'HMA-99120',
        'offer_name' => 'Casino Deposit',
    ]));

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(15.0)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('85610')
        // The goal that fired names the conversion better than the offer does.
        ->and($dto->offerName)->toBe('first deposit')
        ->and($dto->networkTxId)->toBe('HMA-99120');
});

it('treats payout as cents and pay as dollars', function () {
    $driver = new HangMyAdsDriver($this->network);

    $fromCents = $driver->parsePostback(Request::create('/postback/hangmyads', 'GET', [
        'subid' => 'click-uuid',
        'payout' => '1500',
    ]));
    $fromPay = $driver->parsePostback(Request::create('/postback/hangmyads', 'GET', [
        'subid' => 'click-uuid',
        'pay' => '15.00',
        'payout' => '9999',
    ]));

    expect($fromCents->payout)->toBe(15.0)
        // `{pay}` is the conversion-code dollar amount; `{payout}` is cents and
        // must not win when both arrive.
        ->and($fromPay->payout)->toBe(15.0);
});

it('prefers a real transaction id over the composite', function () {
    $dto = (new HangMyAdsDriver($this->network))->parsePostback(Request::create('/postback/hangmyads', 'GET', [
        'click_id' => 'click-uuid',
        'convid' => 'HMA-99120',
        'pay' => '15.00',
    ]));

    expect($dto->networkTxId)->toBe('HMA-99120')
        // `click_id` is the same slot under their conversion vocabulary.
        ->and($dto->clickId)->toBe('click-uuid');
});

it('keys each goal of a multi-goal offer separately', function () {
    $driver = new HangMyAdsDriver($this->network);
    $payload = ['subid' => 'click-uuid', 'offerid' => '85610'];

    // One offer can price several goals and `allow_duplicates` lets one click
    // fire more than once. Keyed on click plus offer alone, everything after
    // the first is dropped as a duplicate and never paid for.
    $registration = $driver->parsePostback(Request::create('/postback/hangmyads', 'GET',
        [...$payload, 'goalname' => 'completed_registration', 'pay' => '2.50']));
    $deposit = $driver->parsePostback(Request::create('/postback/hangmyads', 'GET',
        [...$payload, 'goalname' => 'first deposit', 'pay' => '15.00']));

    expect($registration->networkTxId)->not->toBe($deposit->networkTxId)
        ->and($deposit->networkTxId)->toBe('click-uuid:85610:first deposit:15');

    // The chargeback is looked up by network_txid, so a reversal has to land on
    // the key its own credit produced — which is why the amount goes in
    // unsigned.
    $reversal = $driver->parsePostback(Request::create('/postback/hangmyads', 'GET',
        [...$payload, 'goalname' => 'first deposit', 'pay' => '-15.00']));

    expect($reversal->networkTxId)->toBe($deposit->networkTxId)
        ->and($reversal->payout)->toBe(-15.0)
        ->and($reversal->status)->toBe('reversed');
});

it('does not parse a postback with no subid', function () {
    expect((new HangMyAdsDriver($this->network))->parsePostback(Request::create('/postback/hangmyads', 'GET', [
        'offerid' => '85610',
        'pay' => '15.00',
    ])))->toBeNull();
});

it('does not parse a postback with no money', function () {
    expect((new HangMyAdsDriver($this->network))->parsePostback(Request::create('/postback/hangmyads', 'GET', [
        'subid' => 'click-uuid',
        'offerid' => '85610',
    ])))->toBeNull();
});

it('leaves the allowlist as the only guard', function () {
    // HangMyAds documents no signature and no shared token, so there is nothing
    // to check here. The controller's source-IP allowlist — which rejects when
    // empty — is this network's whole authentication.
    expect((new HangMyAdsDriver($this->network))->verifyPostback(Request::create('/postback/hangmyads')))->toBeTrue();
});

it('puts the click id in the subid slot', function () {
    $driver = new HangMyAdsDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://tracking.hangmytracking.com/click.php?oid=85503&aid=9912&subid={click_id}',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://tracking.hangmytracking.com/click.php?oid=85503&aid=9912&subid=click-uuid');

    // A link with nowhere to put the id is never handed over untouched: an
    // untracked click is a conversion nobody can be paid for.
    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://tracking.hangmytracking.com/click.php?oid=85610&aid=9912',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://tracking.hangmytracking.com/click.php?oid=85610&aid=9912&subid=click-uuid');
});
