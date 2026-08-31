<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\ClickwallDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'clickwall',
        'credentials' => [
            'app_id' => '1054',
            'api_key' => 'clickwall-key',
            'secret_key' => 'clickwall-secret',
        ],
    ]);

    $this->catalogue = fn () => Http::fake([
        'clickwall.net/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/clickwall-offers.json')), true)
        ),
    ]);

    // The postback template this driver publishes, with the macros already
    // substituted the way Clickwall substitutes them — plus `sig`, which they
    // append themselves rather than exposing as a macro.
    $this->postback = fn (array $overrides = []) => Request::create('/postback/clickwall', 'GET', [
        'user' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'reward' => '1500.00',
        'payout' => '1.50',
        'tx' => 'CW-88421',
        'oid' => '24632',
        'offer_name' => 'Dragon Down',
        'status' => 'completed',
        'ip' => '203.0.113.9',
        ...$overrides,
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new ClickwallDriver($this->network))->fetchOffers(), false);

    // The third row carries no id, so there is nothing to key an offer on and
    // nothing to update on the next sweep.
    expect($offers)->toHaveCount(3)
        ->and(collect($offers)->pluck('externalId')->all())->toBe(['24632', '3', '99']);

    expect($offers[0]->name)->toBe('Dragon Down')
        // The offer's own total, not the sum of its events.
        ->and($offers[0]->payout)->toBe(0.8)
        ->and($offers[0]->description)->toBe('Download the game and collect gems...')
        ->and($offers[0]->iconUrl)->toBe('https://clickwall.net/api/image/?icon=24632')
        ->and($offers[0]->countries)->toBe(['US'])
        ->and($offers[0]->devices)->toBe(['android'])
        ->and($offers[0]->categories)->toBe(['app'])
        // `app` is theirs; we store it as the CPI it is.
        ->and($offers[0]->conversionType)->toBe('cpi')
        ->and($offers[0]->isMultiReward)->toBeTrue()
        ->and($offers[0]->goals)->toBe([
            ['name' => 'gems_150', 'payout' => 0.096],
            ['name' => 'gems_1000', 'payout' => 0.704],
        ])
        ->and($offers[0]->trackingUrl)
        ->toBe('https://clickwall.net/app/tracking/10169/24632/{click_id}/R2V0X0xpbmtfYXBp');

    expect($offers[1]->payout)->toBe(1.5)
        ->and($offers[1]->conversionType)->toBe('cpi')
        // "UK" is theirs and is not an ISO code. Stored as-is it targets a
        // country nothing geolocates to, and every British user is filtered
        // out of an offer bought for them.
        ->and($offers[1]->countries)->toBe(['GB', 'CA'])
        // `Windows` is theirs; `parseDevices()` does not know it.
        ->and($offers[1]->devices)->toBe(['desktop'])
        ->and($offers[1]->isMultiReward)->toBeFalse()
        ->and($offers[1]->trackingUrl)
        ->toBe('https://clickwall.net/app/tracking/10000/AAAA/{click_id}');

    expect($offers[2]->payout)->toBe(0.75)
        ->and($offers[2]->conversionType)->toBe('survey')
        // `All` is not ISO and not a device we know, so both lists stay empty
        // — worldwide, every device — rather than storing a fake country.
        ->and($offers[2]->countries)->toBe([])
        ->and($offers[2]->devices)->toBe([])
        ->and($offers[2]->iconUrl)->toBeNull()
        // No click id slot in this link, so one is opened for it.
        ->and($offers[2]->trackingUrl)
        ->toBe('https://clickwall.net/app/tracking/10000/SURVEY?user_id={click_id}');
});

it('authenticates with the app and its key, in one unpaginated call', function () {
    ($this->catalogue)();

    iterator_to_array((new ClickwallDriver($this->network))->fetchOffers(), false);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://clickwall.net/api/')
        && $request['app_id'] === '1054'
        && $request['api_key'] === 'clickwall-key');
});

it('reports why the network refused rather than just the status code', function () {
    Http::fake(['clickwall.net/*' => Http::response(['message' => 'Invalid API key'], 401)]);

    $result = (new ClickwallDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 401')
        ->and($result->message)->toContain('Invalid API key');
});

it('counts the catalogue behind the test connection button', function () {
    ($this->catalogue)();

    $result = (new ClickwallDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(4);
});

it('treats a success:false envelope carrying http 200 as a failure', function () {
    // Read as an empty catalogue it deactivates every Clickwall offer we have,
    // and the connection test goes green on a typo.
    Http::fake([
        'clickwall.net/*' => Http::response(['success' => false, 'message' => 'API access pending']),
    ]);

    $driver = new ClickwallDriver($this->network);

    expect($driver->testConnection()->ok)->toBeFalse()
        ->and($driver->testConnection()->message)->toContain('API access pending');

    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'API access pending');
});

it('refuses the catalogue when the api key has not been approved yet', function () {
    $this->network->update(['credentials' => [
        'app_id' => '1054',
        'secret_key' => 'clickwall-secret',
    ]]);

    $driver = new ClickwallDriver($this->network);

    expect($driver->testConnection()->ok)->toBeFalse()
        ->and($driver->testConnection()->message)->toContain('API key is missing');

    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'API key is missing');
});

it('parses a credited postback', function () {
    $dto = (new ClickwallDriver($this->network))->parsePostback(($this->postback)());

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        // USD, not the 1500 coins in `reward`.
        ->and($dto->payout)->toBe(1.5)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('24632')
        ->and($dto->offerName)->toBe('Dragon Down')
        ->and($dto->ip)->toBe('203.0.113.9')
        ->and($dto->networkTxId)->toBe('CW-88421');
});

it('reverses on the status flag and on the sign, and keeps the credit key', function () {
    $driver = new ClickwallDriver($this->network);

    // A reversal quotes the original TXID, which is how `reverse()` finds the
    // conversion to charge back — so the key has to come out identical.
    $reversal = $driver->parsePostback(($this->postback)(['status' => 'rejected', 'payout' => '-1.50']));

    expect($reversal->networkTxId)->toBe('CW-88421')
        ->and($reversal->payout)->toBe(-1.5)
        ->and($reversal->status)->toBe('reversed');

    // Either signal alone still reverses. Reading this backwards credits a
    // chargeback, which is money that does not come back.
    expect($driver->parsePostback(($this->postback)(['status' => 'rejected']))->status)->toBe('reversed')
        ->and($driver->parsePostback(($this->postback)(['payout' => '-1.50']))->status)->toBe('reversed');
});

it('does not parse a postback missing the click id, the transaction id, or the usd payout', function () {
    $driver = new ClickwallDriver($this->network);

    expect($driver->parsePostback(($this->postback)(['user' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['tx' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['payout' => ''])))->toBeNull()
        // Coins in `reward` are not a substitute for USD.
        ->and($driver->parsePostback(($this->postback)(['payout' => 'coins'])))->toBeNull();
});

it('verifies the documented signature', function () {
    $driver = new ClickwallDriver($this->network);

    // HMAC-SHA256("user_id={user}&oid={oid}&amount={reward}&tx_id={tx}", secret).
    $sig = hash_hmac(
        'sha256',
        'user_id=9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f&oid=24632&amount=1500.00&tx_id=CW-88421',
        'clickwall-secret',
    );

    expect($driver->verifyPostback(($this->postback)(['sig' => $sig])))->toBeTrue()
        // Hex is hex whichever case it arrives in.
        ->and($driver->verifyPostback(($this->postback)(['sig' => strtoupper($sig)])))->toBeTrue()
        // Their hash covers coins (`reward`), not USD (`payout`). Changing the
        // ledger figure does not invalidate the signature — that is their
        // recipe, not a bug in this comparison.
        ->and($driver->verifyPostback(($this->postback)(['sig' => $sig, 'payout' => '999.00'])))->toBeTrue()
        // A postback that quotes a different offer against the same
        // transaction no longer matches the hash it came with.
        ->and($driver->verifyPostback(($this->postback)(['sig' => $sig, 'oid' => '99999'])))->toBeFalse()
        // Casting `1500.00` to float would produce `1500` and fail a
        // signature that was never wrong.
        ->and($driver->verifyPostback(($this->postback)([
            'sig' => hash_hmac('sha256', 'user_id=9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f&oid=24632&amount=1500&tx_id=CW-88421', 'clickwall-secret'),
        ])))->toBeFalse()
        ->and($driver->verifyPostback(($this->postback)(['sig' => 'not-the-hash'])))->toBeFalse()
        ->and($driver->verifyPostback(($this->postback)()))->toBeFalse();
});

it('fails closed when the secret is missing', function () {
    $this->network->update(['credentials' => ['app_id' => '1054', 'api_key' => 'clickwall-key']]);

    $sig = hash_hmac(
        'sha256',
        'user_id=9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f&oid=24632&amount=1500.00&tx_id=CW-88421',
        '',
    );

    expect((new ClickwallDriver($this->network))->verifyPostback(($this->postback)(['sig' => $sig])))->toBeFalse();
});

it('puts the click id in the user_id slot', function () {
    $driver = new ClickwallDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://clickwall.net/app/tracking/10169/24632/{click_id}/R2V0X0xpbmtfYXBp',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://clickwall.net/app/tracking/10169/24632/click-uuid/R2V0X0xpbmtfYXBp');

    // A link with nowhere to put the id is never handed over untouched: an
    // untracked click is a conversion nobody can be paid for.
    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://clickwall.net/app/tracking/10000/SURVEY',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://clickwall.net/app/tracking/10000/SURVEY?user_id=click-uuid');
});
