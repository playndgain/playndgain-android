<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\OfferyDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'offery',
        'credentials' => [
            'api_key' => 'offery-key',
            'secret_key' => 'offery-secret',
        ],
    ]);

    $this->catalogue = fn () => Http::fake([
        'offery.io/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/offery-offers.json')), true)
        ),
    ]);

    // Their documented postback, POSTed the way they send it.
    $this->postback = fn (array $overrides = []) => Request::create('/postback/offery', 'POST', [
        'subId' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'transId' => 'XX-12345678',
        'offer_id' => '9529',
        'offer_name' => 'Offery - Register and Earn',
        'offer_type' => 'offer',
        'reward' => '1.50',
        'reward_name' => 'Coins',
        'reward_value' => '1000.00',
        'payout' => '0.100000',
        'userIp' => '203.0.113.9',
        'country' => 'US',
        'status' => '1',
        'debug' => '0',
        ...$overrides,
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new OfferyDriver($this->network))->fetchOffers(), false);

    // The fourth row carries no offer id, so there is nothing to key an offer on
    // and nothing to update on the next sweep.
    expect($offers)->toHaveCount(3)
        ->and(collect($offers)->pluck('externalId')->all())->toBe(['9529', '1187', '2043']);

    expect($offers[0]->name)->toBe('Opera GX')
        // `payout.usd` is the offer total and the events do not add up to it —
        // 0.50 + 0 + 3.09 is 3.59. Recomputing would price the offer at
        // something the network never agreed to pay.
        ->and($offers[0]->payout)->toBe(4.36)
        ->and($offers[0]->description)->toBe('Install the browser built for gamers')
        // What the user has to do is instructions, not a blurb.
        ->and($offers[0]->instructions)->toBe('Download, install and open Opera GX')
        ->and($offers[0]->iconUrl)->toBe('https://offery.io/img/offers/9529.png')
        ->and($offers[0]->countries)->toBe(['US', 'CA'])
        ->and($offers[0]->devices)->toBe(['android', 'ios'])
        ->and($offers[0]->conversionType)->toBe('cpa')
        ->and($offers[0]->isMultiReward)->toBeTrue()
        // The zero-payout middle step is still a step the user completes, so it
        // is listed rather than hidden.
        ->and($offers[0]->goals)->toBe([
            ['name' => 'Install', 'payout' => 0.5],
            ['name' => 'Reach Level 10', 'payout' => 0.0],
            ['name' => 'Finish Game', 'payout' => 3.09],
        ])
        // The trailing `USER_ID` segment is the subid slot.
        ->and($offers[0]->trackingUrl)
        ->toBe('https://offery.io/click/7o2mrg9zqmg7xewf66mbk7pek11r1v/9529/{click_id}');

    // `PC` is their word for desktop and `parseDevices()` does not know it. An
    // empty device list means *every* device, so an unmapped PC-only offer is
    // shown to the phone users it cannot pay for.
    expect($offers[1]->devices)->toBe(['desktop'])
        ->and($offers[1]->payout)->toBe(0.75)
        ->and($offers[1]->isMultiReward)->toBeFalse()
        // Their `excluded_countries` has nowhere to live: `offers.countries` is
        // an allowlist. An empty allowlist is worldwide, which is what this
        // offer becomes — the documented cost of storing only half the object.
        ->and($offers[1]->countries)->toBe([])
        // No slot in this link, so one is opened as a final path segment.
        ->and($offers[1]->trackingUrl)
        ->toBe('https://offery.io/click/7o2mrg9zqmg7xewf66mbk7pek11r1v/1187/{click_id}');

    expect($offers[2]->countries)->toBe(['DE'])
        ->and($offers[2]->devices)->toBe([])
        ->and($offers[2]->description)->toBeNull()
        ->and($offers[2]->instructions)->toBeNull()
        ->and($offers[2]->iconUrl)->toBeNull()
        ->and($offers[2]->goals)->toBe([]);
});

it('authenticates with the api key alone, in one unfiltered call', function () {
    ($this->catalogue)();

    iterator_to_array((new OfferyDriver($this->network))->fetchOffers(), false);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://offery.io/api/')
        && str_contains($request->url(), 'apiKey=offery-key')
        && ! str_contains($request->url(), 'apikey=')
        && $request['apiKey'] === 'offery-key'
        // No `countries` or `device` filter: narrowing here means an offer that
        // exists for Germany is never stored, so no German user ever sees it.
        // The wall targets off the stored lists instead.
        && ! isset($request['countries'])
        && ! isset($request['device']));
});

it('reports why the network refused rather than just the status code', function () {
    Http::fake(['offery.io/*' => Http::response(['status' => 'error', 'message' => 'Invalid API key'], 401)]);

    $result = (new OfferyDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 401')
        ->and($result->message)->toContain('Invalid API key');
});

it('counts the catalogue behind the test connection button', function () {
    ($this->catalogue)();

    $result = (new OfferyDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(4);
});

it('treats a non-success envelope carrying http 200 as a failure', function () {
    // Read as an empty catalogue it deactivates every Offery offer we have, and
    // the connection test goes green on a typo.
    Http::fake([
        'offery.io/*' => Http::response(['status' => 'error', 'message' => 'No active publisher']),
    ]);

    $driver = new OfferyDriver($this->network);

    expect($driver->testConnection()->ok)->toBeFalse()
        ->and($driver->testConnection()->message)->toContain('No active publisher');

    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'No active publisher');
});

it('parses a credited postback in dollars, not in the publisher currency', function () {
    $dto = (new OfferyDriver($this->network))->parsePostback(($this->postback)());

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        // `payout` is USD; `reward` is the same conversion run through the
        // publisher's exchange rate. Crediting 1.50 here would overpay by a
        // factor of the rate — fifteen times over on this fixture.
        ->and($dto->payout)->toBe(0.1)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('9529')
        ->and($dto->offerName)->toBe('Offery - Register and Earn')
        ->and($dto->ip)->toBe('203.0.113.9')
        // A real transaction id, so no composite key is needed.
        ->and($dto->networkTxId)->toBe('XX-12345678')
        // `subId` is our click id echoed back, not an identifier of theirs.
        ->and($dto->endUserId)->toBeNull();
});

it('charges back on status 2, keeping the key its credit produced', function () {
    // A chargeback quotes the original transId, which is how `reverse()` finds
    // the conversion to charge back — so the key has to come out identical.
    // Their docs are explicit that the amounts stay absolute, so status is the
    // only direction signal there is. Reading it backwards credits a chargeback.
    $reversal = (new OfferyDriver($this->network))->parsePostback(($this->postback)(['status' => '2']));

    expect($reversal->networkTxId)->toBe('XX-12345678')
        ->and($reversal->payout)->toBe(-0.1)
        ->and($reversal->status)->toBe('reversed');
});

it('records a debug fire without paying for it', function () {
    $driver = new OfferyDriver($this->network);

    // Signed and genuine, so it is not discarded — but `rejected` writes the
    // conversion row and moves no money.
    expect($driver->parsePostback(($this->postback)(['debug' => '1']))->status)->toBe('rejected')
        // And a debug chargeback must not claw back a real credit either, so
        // debug is read before status.
        ->and($driver->parsePostback(($this->postback)(['debug' => '1', 'status' => '2']))->status)
        ->toBe('rejected');
});

it('does not parse a postback missing the click id, the transaction id or the payout', function () {
    $driver = new OfferyDriver($this->network);

    // The first two are terms of the signature, so a fire without them cannot
    // be verified either.
    expect($driver->parsePostback(($this->postback)(['subId' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['transId' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['payout' => 'free'])))->toBeNull();
});

it('verifies the documented signature over the reward string they sent', function () {
    $driver = new OfferyDriver($this->network);

    // md5(subId + transId + reward + secretKey), from their docs — and `reward`
    // as the string it arrived as. Casting `1.50` to float and back yields
    // `1.5`, and every postback then fails a signature that was never wrong,
    // which looks exactly like an outage on their side.
    $signature = md5('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f'.'XX-12345678'.'1.50'.'offery-secret');

    expect($driver->verifyPostback(($this->postback)(['signature' => $signature])))->toBeTrue()
        // Hex is hex whichever case it arrives in.
        ->and($driver->verifyPostback(($this->postback)(['signature' => strtoupper($signature)])))->toBeTrue()
        // The float round-trip this test exists to catch.
        ->and($driver->verifyPostback(($this->postback)([
            'signature' => md5('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f'.'XX-12345678'.'1.5'.'offery-secret'),
        ])))->toBeFalse()
        // A postback that restates the amount against the same transaction no
        // longer matches the signature it came with.
        ->and($driver->verifyPostback(($this->postback)([
            'signature' => $signature,
            'reward' => '150.00',
        ])))->toBeFalse()
        ->and($driver->verifyPostback(($this->postback)(['signature' => 'not-the-signature'])))->toBeFalse()
        ->and($driver->verifyPostback(($this->postback)()))->toBeFalse();
});

it('fails closed when the secret is missing', function () {
    // The secret is a required credential, so a blank one is a misconfiguration
    // rather than permission to accept whatever arrives.
    $this->network->update(['credentials' => ['api_key' => 'offery-key']]);

    $signature = md5('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f'.'XX-12345678'.'1.50');

    expect((new OfferyDriver($this->network))->verifyPostback(($this->postback)(['signature' => $signature])))
        ->toBeFalse();
});

it('puts the click id in the trailing path segment', function () {
    $driver = new OfferyDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://offery.io/click/7o2mrg9zqmg7xewf66mbk7pek11r1v/9529/{click_id}',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://offery.io/click/7o2mrg9zqmg7xewf66mbk7pek11r1v/9529/click-uuid');

    // A link with nowhere to put the id is never handed over untouched: an
    // untracked click is a conversion nobody can be paid for.
    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://offery.io/click/7o2mrg9zqmg7xewf66mbk7pek11r1v/9529/',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://offery.io/click/7o2mrg9zqmg7xewf66mbk7pek11r1v/9529/click-uuid');
});
