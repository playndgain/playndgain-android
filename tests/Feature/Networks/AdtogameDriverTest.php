<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\AdtogameDriver;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->network = Network::factory()->create([
        'driver' => 'adtogame',
        'credentials' => ['api_key' => 'eflow-test-key'],
    ]);

    // Six rows and `total_count: 6`, so the whole catalogue arrives in one page
    // and the loop has nothing left to ask for.
    $this->catalogue = fn () => Http::fake([
        'api.eflow.team/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/Networks/adtogame-offers.json')), true),
        ),
    ]);
});

it('maps a recorded catalogue into normalized offers', function () {
    ($this->catalogue)();

    $offers = iterator_to_array((new AdtogameDriver($this->network))->fetchOffers(), false);

    // Four of the six rows are not tiles anyone can click: one is paused, one is
    // priced in euros, one pays revenue share only, and one has no tracking link
    // because we are not approved for it.
    expect($offers)->toHaveCount(2)
        ->and(collect($offers)->pluck('externalId')->all())->toBe(['4412', '4527']);

    expect($offers[0]->name)->toBe('Raid Legends - US - Android - CPI')
        ->and($offers[0]->payout)->toBe(1.4)
        // `html_description` is markup by name. The wall escapes what it prints,
        // so it is flattened here rather than shown as `<b>reach level 10</b>`.
        ->and($offers[0]->description)->toBe('Install the game and reach level 10. New players only.')
        ->and($offers[0]->iconUrl)->toBe('https://cdn.adtogame.com/thumbs/4412.png')
        ->and($offers[0]->countries)->toBe(['US'])
        ->and($offers[0]->devices)->toBe(['android'])
        ->and($offers[0]->categories)->toBe(['Games'])
        ->and($offers[0]->conversionType)->toBe('cpi')
        ->and($offers[0]->isMultiReward)->toBeFalse()
        // AM: click id lives on sub2. A link with no slot gets that one opened.
        ->and($offers[0]->trackingUrl)
        ->toBe('https://trk.adtogame.com/?offer_id=4412&affiliate_id=1873&sub2={click_id}');

    // Base plus the deposit event. The revenue-share entry prices itself as a
    // percentage, so it adds nothing to the total and is not a step on the wall.
    expect($offers[1]->payout)->toBe(17.5)
        ->and($offers[1]->isMultiReward)->toBeTrue()
        ->and($offers[1]->goals)->toBe([
            ['name' => 'Base', 'payout' => 2.5],
            ['name' => 'First Deposit', 'payout' => 15.0],
        ])
        // FR is an exclude rule. Read as an include it would aim the offer at
        // the one country it may not run in.
        ->and($offers[1]->countries)->toBe(['GB', 'DE'])
        ->and($offers[1]->devices)->toBe(['desktop'])
        ->and($offers[1]->description)->toBeNull()
        ->and($offers[1]->iconUrl)->toBeNull()
        // The link already carries sub2 (their "wall" source tag). Ours
        // replaces that slot and leaves sub1 alone — AM: sub1 is source id.
        ->and($offers[1]->trackingUrl)
        ->toBe('https://trk.adtogame.com/?offer_id=4527&affiliate_id=1873&sub1=legacy&sub2={click_id}');
});

it('sends the api key as a header and asks for one page when that is all there is', function () {
    ($this->catalogue)();

    iterator_to_array((new AdtogameDriver($this->network))->fetchOffers(), false);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('X-Eflow-Api-Key', 'eflow-test-key')
        && str_contains($request->url(), 'page=1'));
});

it('pages until the reported total is accounted for', function () {
    Http::fake([
        'api.eflow.team/*' => Http::sequence()
            ->push(['offers' => [adtogameRow(1), adtogameRow(2)], 'paging' => ['total_count' => 3]])
            ->push(['offers' => [adtogameRow(3)], 'paging' => ['total_count' => 3]]),
    ]);

    $offers = iterator_to_array((new AdtogameDriver($this->network))->fetchOffers(), false);

    expect($offers)->toHaveCount(3);
    Http::assertSentCount(2);
});

it('stops rather than looping when the endpoint ignores the page number', function () {
    // Skipped rows still count towards the total: counting only what we yield
    // would ask forever for a page the network already sent.
    Http::fake([
        'api.eflow.team/*' => Http::response([
            'offers' => [adtogameRow(1), ['network_offer_id' => 2, 'offer_status' => 'paused']],
            'paging' => ['total_count' => 2],
        ]),
    ]);

    expect(iterator_to_array((new AdtogameDriver($this->network))->fetchOffers(), false))->toHaveCount(1);
    Http::assertSentCount(1);
});

it('fails the connection test on an http error', function () {
    // Everflow rejects a bad key with a real status code, which is the whole of
    // the check — there is no success envelope carrying a failure.
    Http::fake(['api.eflow.team/*' => Http::response(['message' => 'unauthorized'], 401)]);

    $result = (new AdtogameDriver($this->network))->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('HTTP 401');

    // And a catalogue pull throws rather than reporting an empty catalogue,
    // which the sync sweep would read as "deactivate every Adtogame offer".
    expect(fn () => iterator_to_array((new AdtogameDriver($this->network))->fetchOffers(), false))
        ->toThrow(RequestException::class);
});

it('reports the whole catalogue size rather than the page it asked for', function () {
    ($this->catalogue)();

    $result = (new AdtogameDriver($this->network))->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->offerCount)->toBe(6);
});

it('parses a credited postback', function () {
    $dto = (new AdtogameDriver($this->network))->parsePostback(Request::create('/postback/adtogame', 'GET', [
        'user_id' => '9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f',
        'campaign_id' => '4527',
        'campaign_name' => 'Kingdom Wars - EU - CPA',
        'payout' => '15.00',
        'ip' => '203.0.113.9',
    ]));

    expect($dto->clickId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f')
        ->and($dto->payout)->toBe(15.0)
        ->and($dto->status)->toBe('credited')
        ->and($dto->offerExternalId)->toBe('4527')
        ->and($dto->offerName)->toBe('Kingdom Wars - EU - CPA')
        ->and($dto->ip)->toBe('203.0.113.9')
        ->and($dto->networkTxId)->toBe('9f8c1d2e-4a5b-4c6d-8e7f-0a1b2c3d4e5f:4527::15');
});

it('keys each event of a multi-event offer separately', function () {
    $driver = new AdtogameDriver($this->network);
    $payload = ['user_id' => 'click-uuid', 'campaign_id' => '4527'];

    // One offer prices several events and each fires its own postback with the
    // same click and the same offer id. Keyed on those two alone, everything
    // after the first is dropped as a duplicate and never paid for.
    $base = $driver->parsePostback(Request::create('/postback/adtogame', 'GET',
        [...$payload, 'event_name' => 'base', 'payout' => '2.50']));
    $deposit = $driver->parsePostback(Request::create('/postback/adtogame', 'GET',
        [...$payload, 'event_name' => 'first_deposit', 'payout' => '15.00']));

    expect($base->networkTxId)->not->toBe($deposit->networkTxId);

    // The chargeback is looked up by network_txid, so a reversal has to land on
    // the key its own credit produced — which is why the amount goes in
    // unsigned.
    $reversal = $driver->parsePostback(Request::create('/postback/adtogame', 'GET',
        [...$payload, 'event_name' => 'first_deposit', 'payout' => '-15.00']));

    expect($reversal->networkTxId)->toBe($deposit->networkTxId)
        ->and($reversal->payout)->toBe(-15.0)
        ->and($reversal->status)->toBe('reversed');
});

it('ignores a macro their dashboard never filled in', function () {
    // Every parameter here is a macro we asked for by name. One their panel does
    // not know arrives as the literal token — and taken at face value it is the
    // same string on every conversion, so the second one would be dropped as a
    // duplicate of the first and the publisher would be paid once.
    $dto = (new AdtogameDriver($this->network))->parsePostback(Request::create('/postback/adtogame', 'GET', [
        'user_id' => 'click-uuid',
        'campaign_id' => '4527',
        'transaction_id' => '{transaction_id}',
        'payout' => '15.00',
    ]));

    expect($dto->networkTxId)->toBe('click-uuid:4527::15');

    // A filled one is preferred over the composite, because it is theirs.
    $withId = (new AdtogameDriver($this->network))->parsePostback(Request::create('/postback/adtogame', 'GET', [
        'user_id' => 'click-uuid',
        'transaction_id' => 'EF-77120',
        'payout' => '15.00',
    ]));

    expect($withId->networkTxId)->toBe('EF-77120');
});

it('reads the click id out of click_id then sub2 before the older slots', function () {
    $driver = new AdtogameDriver($this->network);

    // The paste URL puts our UUID on `click_id={sub2}`. That is the AM slot.
    expect($driver->parsePostback(Request::create('/postback/adtogame', 'GET', [
        'click_id' => 'from-template',
        'sub2' => 'from-sub2',
        'user_id' => 'from-user',
        'sub1' => 'from-source',
        'payout' => '1.40',
    ]))->clickId)->toBe('from-template');

    expect($driver->parsePostback(Request::create('/postback/adtogame', 'GET', [
        'sub2' => 'from-sub2',
        'user_id' => 'from-user',
        'sub1' => 'from-source',
        'payout' => '1.40',
    ]))->clickId)->toBe('from-sub2');

    // In-flight clicks written before the AM slots still resolve.
    expect($driver->parsePostback(Request::create('/postback/adtogame', 'GET', [
        'user_id' => '{user_id}',
        'sub1' => 'click-uuid',
        'payout' => '1.40',
    ]))->clickId)->toBe('click-uuid');
});

it('does not parse a postback with no click id', function () {
    expect((new AdtogameDriver($this->network))->parsePostback(Request::create('/postback/adtogame', 'GET', [
        'campaign_id' => '4527',
        'payout' => '15.00',
    ])))->toBeNull();
});

it('leaves the allowlist as the only guard', function () {
    // Adtogame signs nothing and offers no shared token, so there is nothing to
    // check here. The controller's source-IP allowlist — which rejects when
    // empty — is this network's whole authentication.
    expect((new AdtogameDriver($this->network))->verifyPostback(Request::create('/postback/adtogame')))->toBeTrue();
});

it('puts the click id in sub2', function () {
    $driver = new AdtogameDriver($this->network);

    $tracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://trk.adtogame.com/?offer_id=4412&affiliate_id=1873&sub2={click_id}',
    ]);

    expect($driver->buildTrackingUrl($tracked, 'click-uuid'))
        ->toBe('https://trk.adtogame.com/?offer_id=4412&affiliate_id=1873&sub2=click-uuid');

    // A link with nowhere to put the id is never handed over untouched: an
    // untracked click is a conversion nobody can be paid for.
    $untracked = Offer::factory()->create([
        'network_id' => $this->network->id,
        'tracking_url' => 'https://trk.adtogame.com/?offer_id=4527&affiliate_id=1873',
    ]);

    expect($driver->buildTrackingUrl($untracked, 'click-uuid'))
        ->toBe('https://trk.adtogame.com/?offer_id=4527&affiliate_id=1873&sub2=click-uuid');
});

/**
 * The smallest row this driver will keep, for the paging tests where the field
 * mapping is not what is under test.
 *
 * @return array<string, mixed>
 */
function adtogameRow(int $id): array
{
    return [
        'network_offer_id' => $id,
        'name' => "Offer {$id}",
        'offer_status' => 'active',
        'currency_id' => 'USD',
        'tracking_url' => "https://trk.adtogame.com/?offer_id={$id}&affiliate_id=1873",
        'relationship' => [
            'payouts' => ['entries' => [['entry_name' => '', 'payout_type' => 'cpa', 'payout_amount' => 1.0, 'is_default' => true]]],
        ],
    ];
}
