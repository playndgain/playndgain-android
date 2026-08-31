<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\OGAdsDriver;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'ogads',
        'credentials' => ['api_key' => 'og-api-key', 'postback_key' => 'og-postback-secret'],
    ]);

    $this->catalogue = fn () => Http::fake([
        'downloadlocked.com/api/offers?page=2*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/Networks/ogads-offers-page2.json'))
        ),
        'downloadlocked.com/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/Networks/ogads-offers.json'))
        ),
    ]);

    $this->postback = fn (array $overrides = []) => Request::create('/postback/ogads', 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'offer_id' => '19624243',
        'offer_name' => 'Coin Master',
        'payout' => '3.40',
        'ip' => '41.238.10.4',
        'datetime' => '2026-08-18 16:50:00',
        'key' => 'og-postback-secret',
        ...$overrides,
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new OGAdsDriver($this->network))->fetchOffers(), false);

    // Seven rows on page one, one on page two. Four of page one are refused:
    // targeting rules, a zero payout, a JWT link with no subid slot, a paused
    // row and an expired row.
    expect($offers)->toHaveCount(3);

    expect($offers[0]->externalId)->toBe('19624243')
        // Their internal tail (`- US, CA, UK, Incent, Android`) is stripped.
        ->and($offers[0]->name)->toBe('Coin Master')
        ->and($offers[0]->payout)->toBe(3.40)
        ->and($offers[0]->instructions)->toBe('Install the app, open it, and reach village level 15.')
        // `UK` is theirs and is not ISO-2. Stored as-is it is dropped, and
        // every British user misses an offer bought for them.
        ->and($offers[0]->countries)->toBe(['US', 'CA', 'GB'])
        ->and($offers[0]->devices)->toBe(['android'])
        ->and($offers[0]->categories)->toBe(['Mobile Games'])
        ->and($offers[0]->conversionType)->toBe('cpa');

    // Only the first " - " cuts. `£100 Playstation Store Voucher - UK - {CPA} RS`
    // keeps its own text and loses both metadata segments.
    expect($offers[1]->name)->toBe('£100 Playstation Store Voucher')
        ->and($offers[1]->countries)->toBe(['GB'])
        // `Windows` and `Mac OS` map to nothing on their own; every live row
        // carrying them also carries `Desktop`, which is what lands.
        ->and($offers[1]->devices)->toBe(['android', 'desktop', 'ios'])
        // `cpa_percentage` is still conversion-priced.
        ->and($offers[1]->conversionType)->toBe('cpa');

    // Page two, and a `0000-00-00` expiry is not an expiry.
    expect($offers[2]->externalId)->toBe('88001')
        ->and($offers[2]->name)->toBe('Survey Junkie');
});

it('fills the empty subid slot their links already carry', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new OGAdsDriver($this->network))->fetchOffers(), false);

    // Appending would leave `aff_sub4` on the URL twice, one of them empty.
    expect($offers[0]->trackingUrl)->toContain('aff_sub4={click_id}&aff_sub5=')
        ->and(substr_count($offers[0]->trackingUrl, 'aff_sub4='))->toBe(1);
});

it('walks every page with a bearer token and a json accept header', function () {
    ($this->catalogue)();

    iterator_to_array((new OGAdsDriver($this->network))->fetchOffers(), false);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://downloadlocked.com/api/offers')
        && $request->hasHeader('Authorization', 'Bearer og-api-key')
        && $request->hasHeader('Accept', 'application/json')
        && str_contains($request->url(), 'page=1'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'page=2'));
});

it('calls the tool domain an admin configured', function () {
    $this->network->update(['credentials' => ['api_key' => 'og-api-key', 'api_domain' => 'ogsuite.com']]);

    Http::fake(['ogsuite.com/*' => Http::response(['data' => [], 'meta' => ['last_page' => 1, 'total' => 0]])]);

    iterator_to_array((new OGAdsDriver($this->network))->fetchOffers(), false);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://ogsuite.com/api/offers'));
});

it('surfaces the reason a token was refused instead of a bare status code', function () {
    // A private token against the public API, or a public token here: the key
    // authenticates and still has no ability. "403" alone sends people
    // key-hunting when the fix is to ask for the right token.
    Http::fake([
        'downloadlocked.com/*' => Http::response(['message' => 'Invalid ability provided.'], 403),
    ]);

    $result = (new OGAdsDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('403')
        ->and($result->message)->toContain('Invalid ability provided.');
});

it('reports the catalogue size on a good connection', function () {
    ($this->catalogue)();

    $result = (new OGAdsDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(8);
});

it('throws rather than yielding an empty catalogue when a page fails', function () {
    // An empty yield would retire every OGAds offer we hold.
    Http::fake(['downloadlocked.com/*' => Http::response('<h1>403</h1>', 403)]);

    expect(fn () => iterator_to_array((new OGAdsDriver($this->network))->fetchOffers(), false))
        ->toThrow(RequestException::class);
});

it('refuses a catalogue deeper than the page cap instead of truncating it', function () {
    // Stopping quietly at the cap hands the sync job a partial list it cannot
    // tell from a complete one, and everything past the cap gets deactivated.
    Http::fake([
        'downloadlocked.com/*' => Http::response(['data' => [], 'meta' => ['last_page' => 500, 'total' => 500000]]),
    ]);

    expect(fn () => iterator_to_array((new OGAdsDriver($this->network))->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'over the 50 page cap');
});

it('throws when a 200 carries no offer list', function () {
    Http::fake(['downloadlocked.com/*' => Http::response(['message' => 'Unauthenticated.'])]);

    expect(fn () => iterator_to_array((new OGAdsDriver($this->network))->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'Unauthenticated.');
});

it('parses a credited postback', function () {
    $dto = (new OGAdsDriver($this->network))->parsePostback(($this->postback)());

    expect($dto->networkTxId)->toBe('2026-08-18 16:50:00:19624243:9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(3.40)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('19624243')
        ->and($dto->offerName)->toBe('Coin Master')
        ->and($dto->ip)->toBe('41.238.10.4');
});

it('reads aff_sub4 when the pasted url still uses their slot name', function () {
    $dto = (new OGAdsDriver($this->network))->parsePostback(($this->postback)([
        'click_id' => null,
        'aff_sub4' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
    ]));

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f');
});

it('returns null for a postback with no click id', function () {
    $dto = (new OGAdsDriver($this->network))
        ->parsePostback(Request::create('/postback/ogads', 'GET', ['payout' => '3.40']));

    expect($dto)->toBeNull();
});

it('rejects a tampered postback token', function () {
    $driver = new OGAdsDriver($this->network);

    expect($driver->verifyPostback(($this->postback)()))->toBeTrue()
        ->and($driver->verifyPostback(($this->postback)(['key' => 'guessed'])))->toBeFalse();
});

it('puts the click id in the subid slot', function () {
    $offer = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://jump.offerclk.net/aff_c?offer_id=9164&aff_id=463539&aff_sub4=&aff_sub5=',
    ]);

    expect((new OGAdsDriver($this->network))->buildTrackingUrl($offer, 'click-uuid'))
        ->toBe('https://jump.offerclk.net/aff_c?offer_id=9164&aff_id=463539&aff_sub4=click-uuid&aff_sub5=');
});

it('appends the subid slot when a stored link has none', function () {
    $offer = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://jump.offerclk.net/aff_c?offer_id=9164&aff_id=463539',
    ]);

    expect((new OGAdsDriver($this->network))->buildTrackingUrl($offer, 'click-uuid'))
        ->toBe('https://jump.offerclk.net/aff_c?offer_id=9164&aff_id=463539&aff_sub4=click-uuid');
});
