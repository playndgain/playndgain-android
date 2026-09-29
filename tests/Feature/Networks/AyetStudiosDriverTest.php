<?php

use App\Enums\IncomingPostbackResult;
use App\Models\IncomingPostback;
use App\Models\Network;
use App\Networks\Drivers\AyetStudiosDriver;
use App\Networks\DTO\OfferDTO;
use App\Networks\Support\CountryProbeIps;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * All probes hit one URL, so a fake answers by the country the `ip` places
 * and the platform the `user_agent` names. Unlisted cells answer an empty
 * success, which is what most of the world looks like to one adslot.
 *
 * @return array<string, mixed>
 */
function ayetFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/Networks/ayetstudios-offerwall-{$name}.json")), true);
}

/** @return array<string, string> */
function ayetQuery(ClientRequest $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function ayetCell(ClientRequest $request): string
{
    $query = ayetQuery($request);
    $country = array_search($query['ip'] ?? '', CountryProbeIps::IPS, true) ?: 'SERVER';
    $agent = (string) ($query['user_agent'] ?? '');
    $platform = match (true) {
        str_contains($agent, 'Android') => 'android',
        str_contains($agent, 'iPhone') => 'ios',
        default => 'desktop',
    };

    return "{$country}/{$platform}";
}

/**
 * @param  array<string, mixed>|callable(string): mixed  $cells  "US/android" => body, or a responder
 */
function fakeAyet(array|callable $cells): void
{
    // A fresh factory each time: `Http::fake()` appends stubs behind the
    // first match and keeps the recorded calls, and each call here is meant
    // to be a new upstream with a clean count.
    Http::swap(new HttpFactory(app('events')));

    Http::fake(['www.ayetstudios.com/*' => function (ClientRequest $request) use ($cells) {
        $cell = ayetCell($request);
        $answer = is_callable($cells) ? $cells($cell) : ($cells[$cell] ?? null);

        return $answer instanceof PromiseInterface
            ? $answer
            : Http::response($answer ?? ['status' => 'success', 'num_offers' => 0, 'offers' => []]);
    }]);
}

/** @return array<string, OfferDTO> */
function ayetSync(Network $network): array
{
    $offers = [];

    foreach ((new AyetStudiosDriver($network->fresh()))->fetchOffers() as $dto) {
        $offers[$dto->externalId] = $dto;
    }

    return $offers;
}

beforeEach(function () {
    Sleep::fake();

    $this->network = Network::factory()->create([
        'driver' => 'ayetstudios',
        'sync_interval_minutes' => 60,
        'credentials' => [
            'adslot_id' => '10000',
            'coins_per_usd' => '1000',
            // Hot tier, so these cells are probed first on every run.
            'probe_countries' => 'US, EG',
        ],
    ]);

    $android = ayetFixture('android');
    $ios = ayetFixture('ios');

    $this->cells = [
        'US/android' => $android,
        'US/ios' => $ios,
        // Egypt sees only the multi-platform offer.
        'EG/android' => [...$android, 'offers' => [$android['offers'][1]]],
    ];
});

it('merges every probe into one catalogue whose countries are where each offer was seen', function () {
    fakeAyet($this->cells);

    $offers = ayetSync($this->network);

    expect(array_map('strval', array_keys($offers)))->toBe(['271197', '281296', '289948'])
        ->and($offers['289948']->countries)->toBe(['EG', 'US'])
        ->and($offers['281296']->countries)->toBe(['US'])
        ->and($offers['271197']->countries)->toBe(['US']);

    // An empty list is "worldwide" to the wall; no offer may carry one.
    foreach ($offers as $offer) {
        expect($offer->countries)->not->toBe([]);
    }
});

it('converts coins to USD for flat and task offers', function () {
    fakeAyet($this->cells);

    $offers = ayetSync($this->network);

    // Flat offer: payout_base 15400 coins at 1000 per USD.
    expect($offers['289948']->payout)->toBe(15.4)
        ->and($offers['289948']->isMultiReward)->toBeFalse()
        ->and($offers['289948']->goals)->toBe([])
        ->and($offers['289948']->devices)->toBe(['desktop', 'android', 'ios'])
        ->and($offers['289948']->osMinVersion)->toBeNull()
        // No tag categories, so how it converts and ayeT's shelf stand in.
        ->and($offers['289948']->categories)->toBe(['cpa', 'incent']);

    // Task offer: the sum of its tasks (0 + 1260 + 3150 + 12600), a
    // zero-paying install step included as a step.
    expect($offers['281296']->payout)->toBe(17.01)
        ->and($offers['281296']->isMultiReward)->toBeTrue()
        ->and($offers['281296']->goals)->toBe([
            ['name' => 'Start playing for 30 seconds', 'payout' => 0.0],
            ['name' => 'Track your first steps', 'payout' => 1.26],
            ['name' => 'Earn a total reward of $1', 'payout' => 3.15],
            ['name' => 'Receive your first withdrawal from the app', 'payout' => 12.6],
        ])
        ->and($offers['281296']->devices)->toBe(['android'])
        ->and($offers['281296']->osMinVersion)->toBe('10')
        ->and($offers['281296']->categories)->toBe(['health_and_fitness']);

    expect($offers['271197']->devices)->toBe(['ios'])
        ->and($offers['271197']->osMinVersion)->toBe('15.0');
});

it('asks each cell as a user standing there, with no api key', function () {
    fakeAyet($this->cells);

    ayetSync($this->network);

    $sent = collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
    $us = $sent->first(fn (ClientRequest $request) => ayetCell($request) === 'US/android');
    $desktop = $sent->first(fn (ClientRequest $request) => ayetCell($request) === 'US/desktop');

    expect($us->url())->toStartWith('https://www.ayetstudios.com/offers/offerwall_api/10000?')
        ->and(ayetQuery($us))->not->toHaveKey('apiKey')
        ->and(ayetQuery($us)['num_offers'])->toBe('1000')
        ->and(ayetQuery($us)['ip'])->toBe(CountryProbeIps::for('US'))
        // Opaque, and shaped like a real user's so a probe reads as one.
        ->and(ayetQuery($us)['external_identifier'])->toMatch('/^[0-9a-f]{32}$/')
        ->and(ayetQuery($us))->not->toHaveKey('include_mobile_offers')
        // Desktop answers nothing without it.
        ->and(ayetQuery($desktop)['include_mobile_offers'])->toBe('true');

    // Stable across runs: the same cell is the same "user" every time.
    fakeAyet($this->cells);
    ayetSync($this->network);

    $again = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
        ->first(fn (ClientRequest $request) => ayetCell($request) === 'US/android');

    expect(ayetQuery($again)['external_identifier'])->toBe(ayetQuery($us)['external_identifier']);
});

it('stores the probe link for previews and keeps the click slot for ours', function () {
    fakeAyet($this->cells);

    $offer = ayetSync($this->network)['289948'];

    expect($offer->trackingUrl)->toBe('https://www.ayetstudios.com/s2s/go/example-slug/289948/1000001/TOKEN-probe?pub_id=PUB-00000&sub_id_int=PL-00000&adslot_id=10000');
});

it('stops on a 429 and keeps the countries it already knew', function () {
    fakeAyet($this->cells);
    ayetSync($this->network);

    fakeAyet(fn () => Http::response(['status' => 'error', 'error' => 'Too many consecutive API requests, throttled.'], 429));

    $offers = ayetSync($this->network);

    // One call, then nothing: throttling is a signal to stop, not to retry.
    Http::assertSentCount(1);

    expect(array_map('strval', array_keys($offers)))->toBe(['271197', '281296', '289948'])
        ->and($offers['289948']->countries)->toBe(['EG', 'US']);
});

it('throws rather than yield nothing when no probe has ever succeeded', function () {
    fakeAyet(fn () => Http::response(['status' => 'error', 'error' => 'Too many consecutive API requests, throttled.'], 429));

    expect(fn () => ayetSync($this->network))->toThrow(RuntimeException::class, 'no probe has succeeded');
});

it('forgets cells older than three days', function () {
    fakeAyet($this->cells);
    ayetSync($this->network);

    $this->travel(73)->hours();

    // Every cell probed now answers empty. The old answers are past their
    // age, so they no longer place any offer anywhere.
    fakeAyet([]);

    expect(ayetSync($this->network))->toBe([]);
});

it('fails loudly when every probe of a run fails, stored cells or not', function () {
    fakeAyet($this->cells);
    ayetSync($this->network);

    // Republishing the stored cells here would read as a healthy sync for
    // three days while ayeT answered nothing but errors.
    fakeAyet(fn () => Http::response('upstream down', 502));

    expect(fn () => ayetSync($this->network))->toThrow(RuntimeException::class, 'every probe this run failed (HTTP 502');

    // Three failures in a row end the run; the rest of the matrix is not dialled.
    Http::assertSentCount(3);
});

it('walks the rest of the world stalest first', function () {
    $this->network->update(['credentials' => [...$this->network->credentials, 'probe_countries' => '']]);
    fakeAyet([]);

    $countries = function (): array {
        $seen = collect(Http::recorded())->map(fn (array $pair) => explode('/', ayetCell($pair[0]))[0])->unique()->values()->all();
        fakeAyet([]);

        return $seen;
    };

    ayetSync($this->network);
    $first = $countries();

    ayetSync($this->network);
    $second = $countries();

    // 232 countries over 24 hourly runs is ten a run, never repeating until
    // every country has had its turn.
    expect($first)->toHaveCount(10)
        ->and($second)->toHaveCount(10)
        ->and(array_intersect($first, $second))->toBe([]);
});

it('fails the sync on an unknown adslot instead of spending the run on it', function () {
    fakeAyet(fn () => Http::response(['status' => 'error', 'errorCode' => 101, 'errorMessage' => 'Unknown or inactive adslot or placement.']));

    expect(fn () => ayetSync($this->network))->toThrow(RuntimeException::class, '[101] Unknown or inactive adslot');

    Http::assertSentCount(1);
});

it('refuses to sync without a currency rate', function () {
    $this->network->update(['credentials' => ['adslot_id' => '10000']]);
    fakeAyet($this->cells);

    expect(fn () => ayetSync($this->network))->toThrow(RuntimeException::class, 'coins_per_usd');

    Http::assertNothingSent();
});

it('reports a connected adslot with its offer count', function () {
    fakeAyet(['SERVER/android' => ayetFixture('android')]);

    $result = (new AyetStudiosDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(2);
});

it('reports both of ayeT\'s error envelopes in the connection test', function (mixed $body, int $status, array $expected) {
    fakeAyet(fn () => Http::response($body, $status));

    $result = (new AyetStudiosDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse();

    foreach ($expected as $fragment) {
        expect($result->message)->toContain($fragment);
    }
})->with([
    'http 200 with errorCode' => [
        ['status' => 'error', 'errorCode' => 101, 'errorMessage' => 'Unknown or inactive adslot or placement.'],
        200,
        ['[101]', 'Unknown or inactive adslot or placement.'],
    ],
    'http 429 with error' => [
        ['status' => 'error', 'error' => 'Too many consecutive API requests, throttled.'],
        429,
        ['HTTP 429', 'throttled'],
    ],
    'http 401 with error' => [
        ['status' => 'error', 'error' => 'Unauthorized'],
        401,
        ['HTTP 401', 'Unauthorized'],
    ],
]);

it('parses a credited conversion callback', function () {
    $dto = (new AyetStudiosDriver($this->network))->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'callback_type' => 'conversion',
        'transaction_id' => '55512345',
        'is_chargeback' => '0',
        'payout_usd' => '4.40',
        'currency_amount' => '4400',
        'custom_1' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'external_identifier' => '0123456789abcdef0123456789abcdef',
        'offer_id' => '289948',
        'offer_name' => 'Kovo Credit',
        'ip' => '203.0.113.9',
    ]));

    expect($dto->networkTxId)->toBe('55512345')
        ->and($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(4.4)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('289948')
        ->and($dto->offerName)->toBe('Kovo Credit')
        ->and($dto->ip)->toBe('203.0.113.9')
        ->and($dto->endUserId)->toBeNull();
});

it('never reads a click id out of external_identifier', function () {
    // It carries our hashed user identifier now, which matches no click.
    $dto = (new AyetStudiosDriver($this->network))->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
        'external_identifier' => '0123456789abcdef0123456789abcdef',
    ]));

    expect($dto)->not->toBeNull()
        ->and($dto->clickId)->toBeNull();
});

it('matches a chargeback to the key its own credit was written under', function () {
    $driver = new AyetStudiosDriver($this->network);
    $payload = ['custom_1' => 'click-uuid', 'offer_id' => '289948'];

    $credit = $driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        ...$payload,
        'callback_type' => 'conversion',
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
    ]));

    $chargeback = $driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        ...$payload,
        'callback_type' => 'chargeback',
        'transaction_id' => 'r-55512345',
        'is_chargeback' => '1',
        'payout_usd' => '-4.40',
    ]));

    expect($chargeback->networkTxId)->toBe($credit->networkTxId)
        ->and($chargeback->networkTxId)->toBe('55512345')
        ->and($chargeback->payout)->toBe(-4.4)
        ->and($chargeback->status)->toBe('reversed');
});

it('treats is_chargeback on its own as a reversal', function () {
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

    expect($driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'custom_1' => 'click-uuid',
        'payout_usd' => '4.40',
    ])))->toBeNull();

    expect($driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '55512345',
        'payout_usd' => '',
    ])))->toBeNull();
});

it('warns when a callback implies a different currency rate', function () {
    Log::spy();

    $driver = new AyetStudiosDriver($this->network);

    // 4.40 USD and 4400 coins: the configured 1000 per USD. Silent.
    $driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '1', 'payout_usd' => '4.40', 'currency_amount' => '4400', 'custom_1' => 'c',
    ]));

    Log::shouldNotHaveReceived('warning');

    // 4.40 USD and 5000 coins is 1136 per USD, 13.6% off. Logged, still parsed.
    $dto = $driver->parsePostback(Request::create('/postback/ayetstudios', 'GET', [
        'transaction_id' => '2', 'payout_usd' => '4.40', 'currency_amount' => '5000', 'custom_1' => 'c',
    ]));

    expect($dto)->not->toBeNull()->and($dto->payout)->toBe(4.4);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'currency rate')
        && $context['coins_per_usd'] === 1000.0);
});

it('verifies the signature over the raw query, empty values included', function () {
    $this->network->update(['credentials' => [...$this->network->credentials, 'publisher_api_key' => 'publisher-key']]);

    // ayeT's own recipe, spelled out here so a change to the driver's
    // interpretation has to change this line too.
    $params = ['transaction_id' => '55512345', 'payout_usd' => '4.40', 'custom_1' => '', 'offer_name' => 'Kovo Credit '];
    $signed = $params;
    ksort($signed, SORT_STRING);
    $hash = hash_hmac('sha256', http_build_query($signed, '', '&'), 'publisher-key');

    $request = Request::create('/postback/ayetstudios?'.http_build_query($params), 'GET', [], [], [], [
        'HTTP_X_AYETSTUDIOS_SECURITY_HASH' => $hash,
    ]);

    // What TrimStrings and ConvertEmptyStringsToNull leave behind on the
    // route. Hashing this would drop `custom_1` and the trailing space.
    $request->query->replace(['transaction_id' => '55512345', 'payout_usd' => '4.40', 'custom_1' => null, 'offer_name' => 'Kovo Credit']);

    $driver = new AyetStudiosDriver($this->network->fresh());

    expect($driver->verifyPostback($request))->toBeTrue();

    $request->headers->set('X-Ayetstudios-Security-Hash', str_repeat('0', 64));

    expect($driver->verifyPostback($request))->toBeFalse();
});

it('admits a signed callback with an empty macro through the real route', function () {
    $this->network->update(['credentials' => [...$this->network->credentials, 'publisher_api_key' => 'publisher-key']]);

    $params = [
        'callback_type' => 'conversion',
        'transaction_id' => '55512345',
        'payout_usd' => '4.40',
        'custom_1' => '',
        'offer_name' => 'Kovo Credit ',
    ];
    $signed = $params;
    ksort($signed, SORT_STRING);

    $key = $this->network->fresh()->postback_key;

    $this->withHeaders(['X-Ayetstudios-Security-Hash' => hash_hmac('sha256', http_build_query($signed, '', '&'), 'publisher-key')])
        ->get("/postback/{$key}?".http_build_query($params))
        ->assertOk();

    expect(IncomingPostback::query()->sole()->result)->not->toBe(IncomingPostbackResult::Rejected);
});

it('rejects an unsigned request once a key is configured', function () {
    $this->network->update(['credentials' => [...$this->network->credentials, 'publisher_api_key' => 'publisher-key']]);

    expect((new AyetStudiosDriver($this->network->fresh()))->verifyPostback(
        Request::create('/postback/ayetstudios', 'GET', ['transaction_id' => '55512345'])
    ))->toBeFalse();
});

it('leaves the allowlist as the only guard when no publisher key is configured', function () {
    expect((new AyetStudiosDriver($this->network))->verifyPostback(Request::create('/postback/ayetstudios')))->toBeTrue();
});
