<?php

use App\Enums\ConnectorCategory;
use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\SwaarmDriver;
use App\Networks\DTO\OfferDTO;
use App\Networks\Exceptions\InvalidTenantUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

const SWAARM_FEED = 'https://feed.acme.swaarm-clients.com/feed/v1.2/ads';

beforeEach(function () {
    $this->network = Network::factory()->platform('swaarm')->create([
        'credentials' => [
            'feed_url' => SWAARM_FEED,
            'api_key' => 'swaarm-key',
            'postback_secret' => 'swaarm-postback-secret',
        ],
    ]);

    $this->driver = fn () => new SwaarmDriver($this->network);

    $this->catalogue = fn () => Http::fake([
        'feed.acme.swaarm-clients.com/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/swaarm-ads.json')), true)
        ),
    ]);

    $this->postback = fn (array $overrides = []) => Request::create('/postback/'.$this->network->postback_key, 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'txid' => 'SWX-771',
        'payout' => '1.70',
        'status' => 'Approved',
        'offer_id' => '1001',
        'offer_name' => 'Clean Single Payout',
        'event_id' => '16407',
        'secret' => 'swaarm-postback-secret',
        ...$overrides,
    ]);
});

it('is a platform connector that holds one account per partner', function () {
    $connector = SwaarmDriver::connector();

    expect($connector->label)->toBe('Swaarm')
        ->and($connector->category)->toBe(ConnectorCategory::Platform)
        ->and($connector->supportsMultipleAccounts)->toBeTrue()
        // Swaarm REJECTED after an approval is the advertiser taking it back.
        ->and($connector->rejectedAfterCreditReverses)->toBeTrue();
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $driver = ($this->driver)();
    $offers = iterator_to_array($driver->fetchOffers(), false);
    $byId = collect($offers)->keyBy(fn (OfferDTO $offer): string => $offer->externalId);

    expect(collect($offers)->pluck('externalId')->all())->toBe([
        '1001',
        '1002',
        // Targeted by country: the specific row and what is left of the base.
        '1003~1', '1003~2',
        // Worldwide base skipped, the country variant kept under the bare id.
        '1004',
        // Its targeted payout was sub-id scoped, so only the base price stands.
        '1009',
    ]);

    // `UK` aliased to `GB`, or every British user is filtered out of an offer
    // bought for them.
    expect($byId['1001']->countries)->toBe(['GB', 'US'])
        ->and($byId['1001']->devices)->toBe(['android'])
        ->and($byId['1001']->payout)->toBe(1.4)
        ->and($byId['1001']->osMinVersion)->toBe('9.0')
        ->and($byId['1001']->iconUrl)->toBe('https://cdn.example.test/1001-256.png')
        ->and($byId['1001']->conversionType)->toBe('cpi')
        ->and($byId['1001']->dailyCap)->toBe(250)
        ->and($byId['1001']->categories)->toBe(['Games', 'Casual']);
});

it('prices a tile from the payable events rather than the default action', function () {
    ($this->catalogue)();

    $offer = collect(iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->firstWhere(fn (OfferDTO $row): bool => $row->externalId === '1002');

    // 0.10 + 0.05. The offer's own `payout` is 0.0075 and the QUALITY event's
    // 9.99 is a signal the advertiser watches, not money we are paid.
    expect($offer->payout)->toEqualWithDelta(0.15, 0.000001)
        ->and($offer->isMultiReward)->toBeTrue()
        ->and(collect($offer->goals)->pluck('name')->all())->toBe(['Reach level 5', 'Reach level 20'])
        // `WEB` is every device, not desktop.
        ->and($offer->devices)->toBe([]);
});

it('splits a targeted payout out of the base list it overlaps', function () {
    ($this->catalogue)();

    $rows = collect(iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->filter(fn (OfferDTO $row): bool => str_starts_with($row->externalId, '1003'))
        ->keyBy(fn (OfferDTO $row): string => implode(',', $row->countries));

    // A US user sees $4.50, a Canadian sees $1.00, and neither sees two tiles.
    expect($rows->keys()->all())->toEqualCanonicalizing(['CA,GB', 'US'])
        ->and($rows['US']->payout)->toBe(4.5)
        ->and($rows['CA,GB']->payout)->toBe(1.0);
});

it('records why it refused every offer it dropped', function () {
    ($this->catalogue)();

    $driver = ($this->driver)();
    iterator_to_array($driver->fetchOffers(), false);

    $skips = collect($driver->skippedOffers())->map(fn (array $bucket): array => $bucket['samples']);

    expect($skips['non-USD payout'])->toBe(['1005'])
        ->and($skips['missing tracking link or id'])->toBe(['1007'])
        ->and($skips['targeting dimension the wall cannot filter on'])->toBe(['1008', '1009'])
        ->and($skips[SwaarmDriver::SKIP_NO_VARIANTS])->toBe(['1006'])
        ->and($skips[SwaarmDriver::SKIP_UNBOUNDED_BASE])->toBe(['1004']);
});

it('imports offers when swaarm sends empty disallow scaffolding', function () {
    Http::fake([
        'feed.acme.swaarm-clients.com/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/swaarm-ads-winklelab-shape.json')), true)
        ),
    ]);

    $driver = ($this->driver)();
    $offers = collect(iterator_to_array($driver->fetchOffers(), false))
        ->keyBy(fn (OfferDTO $offer): string => (string) $offer->externalId);
    $skips = collect($driver->skippedOffers())->map(fn (array $bucket): array => $bucket['samples']);

    expect($offers->pluck('externalId')->map(fn (string|int $id): string => (string) $id)->all())->toBe(['1645', '1473'])
        ->and($offers->firstWhere('externalId', '1645')->countries)->toBe(['US'])
        ->and($offers->firstWhere('externalId', '1645')->devices)->toBe(['ios'])
        ->and($offers->firstWhere('externalId', '1645')->iconUrl)->toBe('https://creatives.winklelab.swaarm-clients.com/objects/249/dabble-logo.png')
        ->and($offers->firstWhere('externalId', '1473')->devices)->toBe(['desktop'])
        ->and($offers->firstWhere('externalId', '1473')->payout)->toBe(2.4)
        ->and($skips['targeting dimension the wall cannot filter on'])->toBe(['9001']);
});

it('numbers a multi-variant offer the same way on a second sync', function () {
    ($this->catalogue)();

    $first = collect(iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->pluck('externalId')->all();

    ($this->catalogue)();

    $second = collect(iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->pluck('externalId')->all();

    // Anything else churns the offers table on every pull.
    expect($second)->toBe($first);
});

it('sends both documented spellings of the api key', function () {
    ($this->catalogue)();

    iterator_to_array(($this->driver)()->fetchOffers(), false);

    Http::assertSent(function ($request): bool {
        $query = [];
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

        // D5: the spec says `apiKey`, the v1.3 tenant behind Skylup says
        // `api_key`, and a wrong name is a 403 rather than an empty list.
        return ($query['apiKey'] ?? null) === 'swaarm-key'
            && ($query['api_key'] ?? null) === 'swaarm-key';
    });
});

it('refuses to read an api-level rejection as an empty catalogue', function () {
    Http::fake([
        'feed.acme.swaarm-clients.com/*' => Http::response([
            'errors' => [['reason' => 'Unauthorized', 'message' => 'Publisher does not exist.']],
        ], 403),
    ]);

    // An empty list here deactivates every offer on the account.
    expect(fn () => iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'Publisher does not exist.');
});

it('surfaces a failed connection test without the key in it', function () {
    Http::fake([
        'feed.acme.swaarm-clients.com/*' => Http::response(
            ['errors' => [['reason' => 'Unauthorized', 'message' => 'Publisher does not exist.']]],
            403,
        ),
    ]);

    $result = ($this->driver)()->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Publisher does not exist.')
        ->and($result->message)->not->toContain('swaarm-key');
});

it('refuses a feed url that is not a swaarm tenant', function () {
    $network = Network::factory()->platform('swaarm')->create([
        'credentials' => ['feed_url' => 'https://169.254.169.254/latest/', 'api_key' => 'k', 'postback_secret' => 's'],
    ]);

    Http::fake();

    // Validated again at request time: a row can be written by a console
    // command that never saw the form rule.
    expect(fn () => iterator_to_array((new SwaarmDriver($network))->fetchOffers(), false))
        ->toThrow(InvalidTenantUrl::class);

    Http::assertNothingSent();
});

it('puts the click id in the subid slot exactly once', function () {
    ($this->catalogue)();

    $offers = iterator_to_array(($this->driver)()->fetchOffers(), false);
    $offer = Offer::factory()->make(['tracking_url' => $offers[0]->trackingUrl]);

    $url = ($this->driver)()->buildTrackingUrl($offer, 'CLK-1');

    expect($url)->toBe('https://feed.acme.swaarm-clients.com/click?ad=1001&pub_click_id=CLK-1')
        ->and(substr_count($url, 'pub_click_id'))->toBe(1);
});

it('hands the admin a paste url carrying the macros and this account secret', function () {
    $url = ($this->driver)()->postbackUrl();

    expect($url)->toStartWith(url('/postback/'.$this->network->postback_key))
        ->toContain('txid=#{id}')
        ->toContain('payout=#{payout.theyGetInDollarsExact}')
        ->toContain('click_id=#{click.publisher.clickId}')
        ->toContain('&secret=swaarm-postback-secret')
        // Braces unencoded or the dashboard sends the token back literally.
        ->not->toContain('%7B');
});

it('parses each upstream state into ours', function (string $state, string $status, float $payout) {
    $dto = ($this->driver)()->parsePostback(($this->postback)(['status' => $state]));

    expect($dto->status)->toBe($status)
        ->and($dto->payout)->toBe($payout)
        ->and($dto->networkTxId)->toBe('SWX-771')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f');
})->with([
    'approved' => ['Approved', 'credited', 1.7],
    'upper case' => ['APPROVED', 'credited', 1.7],
    'pending' => ['Pending', 'pending', 1.7],
    // The driver reports the word; whether it costs money is the processor's
    // decision, taken from the row it lands on.
    'rejected' => ['Rejected', 'rejected', 1.7],
    // A word we do not know is never paid on.
    'unknown' => ['Quarantined', 'pending', 1.7],
]);

it('reads an explicitly negative payout as a reversal', function () {
    // A figure rather than a word: the tenant is quoting the amount it takes
    // back, which is a reversal whatever the state says.
    $dto = ($this->driver)()->parsePostback(($this->postback)(['status' => 'Approved', 'payout' => '-1.70']));

    expect($dto->status)->toBe('reversed')
        ->and($dto->payout)->toBe(-1.7);
});

it('keeps the account secret off the parsed postback', function () {
    $dto = ($this->driver)()->parsePostback(($this->postback)());

    // `raw` is persisted on the log row and replayed from it.
    expect($dto->raw['secret'])->toBe('REDACTED')
        ->and($dto->raw['click_id'])->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f');
});

it('refuses a postback missing the parts it needs', function (array $overrides) {
    expect(($this->driver)()->parsePostback(($this->postback)($overrides)))->toBeNull();
})->with([
    'no click id' => [['click_id' => null]],
    'no transaction id' => [['txid' => null]],
    'non numeric payout' => [['payout' => 'free']],
]);

it('accepts its secret from a query parameter, a bearer token or a header', function () {
    $driver = ($this->driver)();

    expect($driver->verifyPostback(($this->postback)()))->toBeTrue();

    $bearer = ($this->postback)(['secret' => null]);
    $bearer->headers->set('Authorization', 'Bearer swaarm-postback-secret');
    expect($driver->verifyPostback($bearer))->toBeTrue();

    $header = ($this->postback)(['secret' => null]);
    $header->headers->set('X-Postback-Secret', 'swaarm-postback-secret');
    expect($driver->verifyPostback($header))->toBeTrue();

    expect($driver->verifyPostback(($this->postback)(['secret' => 'wrong'])))->toBeFalse()
        ->and($driver->verifyPostback(($this->postback)(['secret' => null])))->toBeFalse();
});

it('fails closed when the account has no secret saved yet', function () {
    $network = Network::factory()->platform('swaarm')->create([
        'credentials' => ['feed_url' => SWAARM_FEED, 'api_key' => 'k'],
    ]);

    // `'' === ''` would authenticate every caller on earth, and the
    // half-configured network is exactly when this is reached.
    expect((new SwaarmDriver($network))->verifyPostback(($this->postback)(['secret' => null])))->toBeFalse()
        ->and((new SwaarmDriver($network))->verifyPostback(($this->postback)(['secret' => ''])))->toBeFalse();
});
