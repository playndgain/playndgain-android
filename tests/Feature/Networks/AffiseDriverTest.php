<?php

use App\Enums\ConnectorCategory;
use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\AffiseDriver;
use App\Networks\DTO\OfferDTO;
use App\Networks\Exceptions\InvalidTenantUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * @return array<string, mixed>
 */
function affisePage(int $page): array
{
    return json_decode(
        (string) file_get_contents(base_path("tests/Fixtures/Networks/affise-offers-page{$page}.json")),
        true,
    );
}

beforeEach(function () {
    $this->network = Network::factory()->platform('affise')->create([
        'credentials' => [
            'api_base_url' => 'https://api-acme.affise.com',
            'api_key' => 'affise-key',
            'postback_secret' => 'affise-postback-secret',
        ],
    ]);

    $this->driver = fn () => new AffiseDriver($this->network);

    $this->catalogue = fn () => Http::fakeSequence()
        ->push(affisePage(1))
        ->push(affisePage(2));

    $this->postback = fn (array $overrides = []) => Request::create('/postback/'.$this->network->postback_key, 'GET', [
        'click_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'txid' => 'AFX-9001',
        'payout' => '3.40',
        'currency' => 'USD',
        'status' => '1',
        'offer_id' => '902',
        'offer_name' => 'Country Matrix Install',
        'goal' => '1',
        'secret' => 'affise-postback-secret',
        ...$overrides,
    ]);
});

it('is a platform connector whose late decline takes the money back', function () {
    $connector = AffiseDriver::connector();

    expect($connector->label)->toBe('Affise')
        ->and($connector->category)->toBe(ConnectorCategory::Platform)
        ->and($connector->supportsMultipleAccounts)->toBeTrue()
        ->and($connector->rejectedAfterCreditReverses)->toBeTrue();
});

it('walks every page and expands the payments matrix into rows', function () {
    ($this->catalogue)();

    $offers = iterator_to_array(($this->driver)()->fetchOffers(), false);

    expect(collect($offers)->pluck('externalId')->all())->toBe([
        // One row per country, numbered over a deterministic sort.
        '5718dac83b7d9bf8588b4579~1',
        '5718dac83b7d9bf8588b4579~2',
        '5718dac83b7d9bf8588b4579~3',
        // One audience, two goals: one row carrying both.
        '5718dac83b7d9bf8588b45aa',
        // `required_approval` is not a connection state on the partner
        // endpoint — the documented example row carries it — so it is kept.
        '5718dac83b7d9bf8588b45ee',
    ]);

    $byCountry = collect($offers)
        ->filter(fn (OfferDTO $row): bool => str_starts_with($row->externalId, '5718dac83b7d9bf8588b4579'))
        ->keyBy(fn (OfferDTO $row): string => implode(',', $row->countries));

    expect($byCountry['US']->payout)->toBe(3.4)
        ->and($byCountry['DE']->payout)->toBe(1.2)
        ->and($byCountry['BR']->payout)->toBe(0.6)
        ->and($byCountry['US']->devices)->toBe(['android'])
        ->and($byCountry['US']->conversionType)->toBe('cpi')
        ->and($byCountry['US']->dailyCap)->toBe(400)
        ->and($byCountry['US']->categories)->toBe(['Mobile Games'])
        ->and($byCountry['US']->instructions)->toBe('No incent traffic');
});

it('turns several goals on one audience into a multi-reward offer', function () {
    ($this->catalogue)();

    $offer = collect(iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->firstWhere(fn (OfferDTO $row): bool => $row->externalId === '5718dac83b7d9bf8588b45aa');

    // The tile is the sum, the steps are the list — the same shape a multi-step
    // Ayet offer takes.
    expect($offer->payout)->toBe(10.0)
        ->and($offer->isMultiReward)->toBeTrue()
        ->and(collect($offer->goals)->pluck('name')->all())->toBe(['Registration', 'First deposit'])
        ->and($offer->countries)->toBe(['GB']);
});

it('records why it refused every offer it dropped', function () {
    ($this->catalogue)();

    $driver = ($this->driver)();
    iterator_to_array($driver->fetchOffers(), false);

    $skips = collect($driver->skippedOffers())->map(fn (array $bucket): array => $bucket['samples']);

    expect($skips['percentage payout'])->toBe(['5718dac83b7d9bf8588b45bb'])
        ->and($skips['country exclusion list'])->toBe(['5718dac83b7d9bf8588b45cc'])
        ->and($skips)->not->toHaveKey('requires partner approval')
        ->and($skips['non-USD payout'])->toBe(['5718dac83b7d9bf8588b45ff'])
        ->and($skips['targeting dimension the wall cannot filter on'])->toBe(['5718dac83b7d9bf8588b4600'])
        ->and($skips['offer stopped'])->toBe(['5718dac83b7d9bf8588b4611'])
        // Two prices for one audience is a guess about money, so the whole
        // offer goes rather than one of its variants.
        ->and($skips[AffiseDriver::SKIP_CONFLICTING])->toBe(['5718dac83b7d9bf8588b45dd'])
        // A percent-priced offer has no payable variant left after the skip.
        ->and($skips[AffiseDriver::SKIP_NO_VARIANTS])->toContain('5718dac83b7d9bf8588b45bb');
});

it('sends the key as a header and asks for the largest page it may', function () {
    ($this->catalogue)();

    iterator_to_array(($this->driver)()->fetchOffers(), false);

    Http::assertSent(function ($request): bool {
        $query = [];
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

        return $request->hasHeader('API-Key', 'affise-key')
            && ($query['limit'] ?? null) === '500'
            && str_contains((string) $request->url(), 'api-acme.affise.com/3.0/partner/offers');
    });

    Http::assertSentCount(2);
});

it('aborts before yielding anything when a later page fails', function () {
    Http::fakeSequence()
        ->push(affisePage(1))
        ->push(['status' => 0, 'message' => 'Invalid API key'], 401);

    // Yielding page one first would leave a half-applied catalogue whose missing
    // offers are indistinguishable from inventory the partner dropped.
    expect(fn () => iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'page 2');
});

it('stops rather than looping when a tenant never ends its pagination', function () {
    Http::fake(function ($request) {
        $query = [];
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        // Always one more page. A tenant bug, or a filter we did not send.
        return Http::response([
            'status' => 1,
            'offers' => [],
            'pagination' => ['per_page' => 500, 'total_count' => 10, 'page' => $page, 'next_page' => $page + 1],
        ]);
    });

    expect(fn () => iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'paged past');
});

it('refuses to read a rejected request as an empty catalogue', function () {
    Http::fake(['*' => Http::response(['status' => 0, 'message' => 'Invalid API key'], 403)]);

    expect(fn () => iterator_to_array(($this->driver)()->fetchOffers(), false))
        ->toThrow(RuntimeException::class, 'Invalid API key');
});

it('surfaces a failed connection test without the key in it', function () {
    Http::fake(['*' => Http::response(['status' => 0, 'message' => 'Invalid API key'], 403)]);

    $result = ($this->driver)()->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Invalid API key')
        ->and($result->message)->not->toContain('affise-key');
});

it('refuses an api base url that is not an affise tenant', function () {
    $network = Network::factory()->platform('affise')->create([
        'credentials' => ['api_base_url' => 'https://api.evil.test', 'api_key' => 'k', 'postback_secret' => 's'],
    ]);

    Http::fake();

    expect(fn () => iterator_to_array((new AffiseDriver($network))->fetchOffers(), false))
        ->toThrow(InvalidTenantUrl::class);

    Http::assertNothingSent();
});

it('puts the click id in the sub1 slot exactly once', function () {
    ($this->catalogue)();

    $offers = iterator_to_array(($this->driver)()->fetchOffers(), false);
    $offer = Offer::factory()->make(['tracking_url' => $offers[0]->trackingUrl]);

    $url = ($this->driver)()->buildTrackingUrl($offer, 'CLK-1');

    expect($url)->toBe('https://track.acme.affise.com/click?pid=610&offer_id=902&sub1=CLK-1')
        ->and(substr_count($url, 'sub1'))->toBe(1);
});

it('hands the admin a paste url carrying the macros and this account secret', function () {
    $url = ($this->driver)()->postbackUrl();

    expect($url)->toStartWith(url('/postback/'.$this->network->postback_key))
        ->toContain('click_id={sub1}')
        ->toContain('txid={transactionid}')
        ->toContain('payout={sum}')
        ->toContain('currency={currency}')
        ->toContain('&secret=affise-postback-secret')
        ->not->toContain('%7B');
});

it('parses each documented status into ours', function (string $status, string $expected, float $payout) {
    $dto = ($this->driver)()->parsePostback(($this->postback)(['status' => $status]));

    expect($dto->status)->toBe($expected)
        ->and($dto->payout)->toBe($payout)
        ->and($dto->networkTxId)->toBe('AFX-9001');
})->with([
    'confirmed' => ['1', 'credited', 3.4],
    'pending' => ['2', 'pending', 3.4],
    // A decline is reported as a rejection. On a pending row that is a refusal
    // and on a credited one the matrix charges it back; the driver does not
    // decide which.
    'declined' => ['3', 'rejected', 3.4],
    // Hold is not a decision, so it waits rather than being paid.
    'hold' => ['5', 'pending', 3.4],
    'unknown' => ['77', 'pending', 3.4],
]);

it('reads an explicitly negative sum as a reversal', function () {
    // Affise quoting the amount it takes back is a chargeback figure, not a
    // word, and it is read as one.
    $dto = ($this->driver)()->parsePostback(($this->postback)(['status' => '3', 'payout' => '-3.40']));

    expect($dto->status)->toBe('reversed')
        ->and($dto->payout)->toBe(-3.4);
});

it('refuses a conversion priced in a currency it cannot convert', function () {
    // There is no exchange rate anywhere in this application, and crediting at
    // a guessed one pays a number nobody can reconcile. 422 and a log row.
    expect(($this->driver)()->parsePostback(($this->postback)(['currency' => 'EUR'])))->toBeNull()
        ->and(($this->driver)()->parsePostback(($this->postback)(['currency' => 'usd']))?->status)->toBe('credited')
        // Absent means USD: the macro is not set on every tenant.
        ->and(($this->driver)()->parsePostback(($this->postback)(['currency' => null]))?->status)->toBe('credited');
});

it('builds a stable key when the tenant sends no transaction id', function () {
    $credit = ($this->driver)()->parsePostback(($this->postback)(['txid' => null]));
    $decline = ($this->driver)()->parsePostback(($this->postback)(['txid' => null, 'status' => '3']));

    // `reverse()` matches on the key, so the decline has to carry the same one
    // its credit did.
    expect($credit->networkTxId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f:902:1')
        ->and($decline->networkTxId)->toBe($credit->networkTxId);
});

it('refuses a postback missing the parts it needs', function (array $overrides) {
    expect(($this->driver)()->parsePostback(($this->postback)($overrides)))->toBeNull();
})->with([
    'no click id' => [['click_id' => null]],
    'non numeric payout' => [['payout' => 'free']],
]);

it('keeps the account secret off the parsed postback', function () {
    $dto = ($this->driver)()->parsePostback(($this->postback)());

    expect($dto->raw['secret'])->toBe('REDACTED')
        ->and($dto->raw['click_id'])->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f');
});

it('verifies the secret from the query and fails closed without one', function () {
    $driver = ($this->driver)();

    expect($driver->verifyPostback(($this->postback)()))->toBeTrue()
        ->and($driver->verifyPostback(($this->postback)(['secret' => 'wrong'])))->toBeFalse()
        ->and($driver->verifyPostback(($this->postback)(['secret' => null])))->toBeFalse();

    $unconfigured = Network::factory()->platform('affise')->create([
        'credentials' => ['api_base_url' => 'https://api-acme.affise.com', 'api_key' => 'k'],
    ]);

    expect((new AffiseDriver($unconfigured))->verifyPostback(($this->postback)(['secret' => ''])))->toBeFalse();
});
