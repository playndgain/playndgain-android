<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\GemiAdDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'gemiad',
        'credentials' => [
            'placement_id' => 'placement123',
            'api_key' => 'gemi-key',
            'secret_key' => 'gemi-secret',
        ],
    ]);

    $this->catalogue = fn () => Http::fake([
        'api.gemiwall.com/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/gemiad-offers.json')), true)
        ),
    ]);

    // The postback template this driver publishes, with the macros already
    // substituted the way GemiAd substitutes them.
    $this->postback = fn (array $overrides = []) => Request::create('/postback/gemiad', 'GET', [
        'userId' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'offerId' => 'offer123',
        'offerName' => 'Download App',
        'eventName' => 'Complete tutorial',
        'payout' => '100.50',
        'txid' => 'GW-88421',
        'status' => 'completed',
        'ip' => '203.0.113.9',
        ...$overrides,
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new GemiAdDriver($this->network))->fetchOffers(), false);

    // The third row carries no id, so there is nothing to key an offer on and
    // nothing to update on the next sweep.
    expect($offers)->toHaveCount(2)
        ->and(collect($offers)->pluck('externalId')->all())->toBe(['offer123', 'offer456']);

    expect($offers[0]->name)->toBe('Download App')
        // The offer's own total, not the sum of its events.
        ->and($offers[0]->payout)->toBe(150.5)
        // Localized: the blurb is under `description.en`, not on `description`.
        ->and($offers[0]->description)->toBe('Download and install the app to earn rewards')
        ->and($offers[0]->iconUrl)->toBe('https://cdn.gemiwall.com/icon.png')
        ->and($offers[0]->previewUrl)->toBe('https://play.google.com/store/apps/details?id=com.example.app')
        // "UK" is theirs and is not an ISO code. Stored as-is it targets a
        // country nothing geolocates to, and every British user is filtered
        // out of an offer bought for them.
        ->and($offers[0]->countries)->toBe(['US', 'GB', 'CA'])
        ->and($offers[0]->devices)->toBe(['android', 'ios'])
        ->and($offers[0]->categories)->toBe(['app'])
        ->and($offers[0]->conversionType)->toBe('cpi')
        ->and($offers[0]->dailyCap)->toBe(1000)
        ->and($offers[0]->isMultiReward)->toBeTrue()
        // Each step is labelled by its localized action, and the intermediate
        // one their docs describe — an event with no payout — is still a step
        // the user has to complete, so it is listed at zero rather than hidden.
        ->and($offers[0]->goals)->toBe([
            ['name' => 'Install and open the app', 'payout' => 50.0],
            ['name' => 'Reach level 5', 'payout' => 0.0],
            ['name' => 'Complete tutorial', 'payout' => 100.5],
        ])
        ->and($offers[0]->trackingUrl)
        ->toBe('https://api.gemiwall.com/api/offers/click?offerId=offer123&userId={click_id}&placementId=placement123');

    expect($offers[1]->payout)->toBe(1.75)
        // CPE is theirs and not ours; it falls to `cpa` rather than failing the
        // sync over a label.
        ->and($offers[1]->conversionType)->toBe('cpa')
        ->and($offers[1]->countries)->toBe(['TR'])
        ->and($offers[1]->devices)->toBe(['desktop'])
        ->and($offers[1]->categories)->toBe(['survey'])
        ->and($offers[1]->previewUrl)->toBeNull()
        // null is unlimited, not zero.
        ->and($offers[1]->dailyCap)->toBeNull()
        ->and($offers[1]->isMultiReward)->toBeFalse()
        // No click id slot in this link, so one is opened for it.
        ->and($offers[1]->trackingUrl)
        ->toBe('https://api.gemiwall.com/api/offers/click?offerId=offer456&placementId=placement123&userId={click_id}');
});

it('authenticates with the placement and its key, in one unpaginated call', function () {
    ($this->catalogue)();

    iterator_to_array((new GemiAdDriver($this->network))->fetchOffers(), false);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        // Their docs publish the endpoint as http; the key rides in the query
        // string, so this driver asks for it over TLS.
        && str_starts_with($request->url(), 'https://api.gemiwall.com/api/offers/static')
        && $request['placementId'] === 'placement123'
        && $request['apiKey'] === 'gemi-key');
});

it('reports why the network refused rather than just the status code', function () {
    // 400 a missing placement, 401 a bad key, 404 an inactive publisher, 429
    // the five-requests-a-minute limit. The code alone does not tell the admin
    // which field they pasted wrong.
    Http::fake(['api.gemiwall.com/*' => Http::response(['error' => 'Invalid API key'], 401)]);

    $result = (new GemiAdDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 401')
        ->and($result->message)->toContain('Invalid API key');
});

it('counts the catalogue behind the test connection button', function () {
    ($this->catalogue)();

    $result = (new GemiAdDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(3);
});

it('treats a success:false envelope carrying http 200 as a failure', function () {
    // Read as an empty catalogue it deactivates every GemiAd offer we have, and
    // the connection test goes green on a typo.
    Http::fake([
        'api.gemiwall.com/*' => Http::response(['success' => false, 'error' => 'Placement not found']),
    ]);

    $driver = new GemiAdDriver($this->network);

    expect($driver->testConnection()->ok)->toBeFalse()
        ->and($driver->testConnection()->message)->toContain('Placement not found');

    expect(fn () => iterator_to_array($driver->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'Placement not found');
});

it('parses a credited postback', function () {
    $dto = (new GemiAdDriver($this->network))->parsePostback(($this->postback)());

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(100.5)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('offer123')
        // The event that fired names the conversion better than the offer does.
        ->and($dto->offerName)->toBe('Complete tutorial')
        ->and($dto->ip)->toBe('203.0.113.9')
        // A real transaction id, so no composite key is needed.
        ->and($dto->networkTxId)->toBe('GW-88421');
});

it('reverses on the status flag and on the sign, and keeps the credit key', function () {
    $driver = new GemiAdDriver($this->network);

    // A reversal quotes the original TXID, which is how `reverse()` finds the
    // conversion to charge back — so the key has to come out identical.
    $reversal = $driver->parsePostback(($this->postback)(['status' => 'rejected', 'payout' => '-100.50']));

    expect($reversal->networkTxId)->toBe('GW-88421')
        ->and($reversal->payout)->toBe(-100.5)
        ->and($reversal->status)->toBe('reversed');

    // Either signal alone still reverses. Reading this backwards credits a
    // chargeback, which is money that does not come back.
    expect($driver->parsePostback(($this->postback)(['status' => 'rejected']))->status)->toBe('reversed')
        ->and($driver->parsePostback(($this->postback)(['payout' => '-100.50']))->status)->toBe('reversed');
});

it('falls back to the offer name when no event fired', function () {
    // Single-event offers send no {EVENT_NAME}, so the macro arrives empty.
    $dto = (new GemiAdDriver($this->network))->parsePostback(($this->postback)(['eventName' => '']));

    expect($dto->offerName)->toBe('Download App');
});

it('does not parse a postback missing the click id or the transaction id', function () {
    $driver = new GemiAdDriver($this->network);

    // Both are in the hash, so a fire without them cannot be verified either.
    expect($driver->parsePostback(($this->postback)(['userId' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['txid' => ''])))->toBeNull()
        ->and($driver->parsePostback(($this->postback)(['payout' => 'free'])))->toBeNull();
});

it('verifies the documented signature', function () {
    $driver = new GemiAdDriver($this->network);

    // sha256(userId + offerId + txId + secretKey), from their docs.
    $hash = hash('sha256', '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f'.'offer123'.'GW-88421'.'gemi-secret');

    expect($driver->verifyPostback(($this->postback)(['hash' => $hash])))->toBeTrue()
        // Hex is hex whichever case it arrives in.
        ->and($driver->verifyPostback(($this->postback)(['hash' => strtoupper($hash)])))->toBeTrue()
        // A postback that quotes a different offer against the same transaction
        // no longer matches the hash it came with.
        ->and($driver->verifyPostback(($this->postback)(['hash' => $hash, 'offerId' => 'offer999'])))->toBeFalse()
        ->and($driver->verifyPostback(($this->postback)(['hash' => 'not-the-hash'])))->toBeFalse()
        ->and($driver->verifyPostback(($this->postback)()))->toBeFalse();
});

it('fails closed when the secret is missing', function () {
    // The secret is a required credential, so a blank one is a misconfiguration
    // rather than permission to accept whatever arrives.
    $this->network->update(['credentials' => ['placement_id' => 'placement123', 'api_key' => 'gemi-key']]);

    $hash = hash('sha256', '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f'.'offer123'.'GW-88421');

    expect((new GemiAdDriver($this->network))->verifyPostback(($this->postback)(['hash' => $hash])))->toBeFalse();
});

it('puts the click id in the userId slot', function () {
    $driver = new GemiAdDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://api.gemiwall.com/api/offers/click?offerId=offer123&userId={click_id}',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://api.gemiwall.com/api/offers/click?offerId=offer123&userId=click-uuid');

    // A link with nowhere to put the id is never handed over untouched: an
    // untracked click is a conversion nobody can be paid for.
    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://api.gemiwall.com/api/offers/click?offerId=offer456',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://api.gemiwall.com/api/offers/click?offerId=offer456&userId=click-uuid');
});
