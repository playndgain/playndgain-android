<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\OutgoingPostbackKind;
use App\Models\App;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\Network;
use App\Models\OfferClick;
use App\Models\Publisher;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\DTO\PostbackDTO;
use App\Networks\NetworkDriverManager;
use App\Services\Money\ConversionProcessor;
use App\Services\Money\MacroResolver;
use Database\Factories\OfferClickFactory;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

/** The embed equivalent of moneyFixture(): a click with no offer on it. */
function embedFixture(array $app = [], array $network = []): OfferClick
{
    app(NetworkDriverManager::class)->register('fake', FakeNetworkDriver::class);
    FakeNetworkDriver::reset();

    $partner = Network::factory()->create(['driver' => 'fake', 'name' => 'Partner Wall Co', ...$network]);
    $owner = Publisher::factory()->create();
    $placement = App::factory()->create(['publisher_id' => $owner->id, ...$app]);

    return OfferClickFactory::new()->create([
        'app_id' => $placement->id,
        'publisher_id' => $owner->id,
        'offer_id' => null,
        'network_id' => $partner->id,
        'end_user_id' => 'USER_1',
    ]);
}

it('credits a partner postback that carries an embed click id', function () {
    Queue::fake();

    $click = embedFixture();

    firePostback(new PostbackDTO(
        networkTxId: 'PTX-1',
        clickId: $click->click_id,
        payout: 0.40,
        status: 'credited',
        offerName: 'Survey 4 minutes',
        endUserId: 'USER_1',
    ))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Approved)
        ->and($conversion->offer_id)->toBeNull()
        ->and($conversion->offer_click_id)->toBe($click->id)
        ->and($conversion->end_user_id)->toBe('USER_1')
        ->and($conversion->offer_name)->toBe('Survey 4 minutes')
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('stores a generic name, never the partner, when the postback carries no offer name', function () {
    Queue::fake();

    $click = embedFixture();

    firePostback(new PostbackDTO(
        networkTxId: 'PTX-2',
        clickId: $click->click_id,
        payout: 0.40,
        status: 'credited',
    ))->assertOk();

    // `conversions.offer_name` is NOT NULL and there is no offer to fall back
    // to, but the column reaches the publisher and the {offer_name} macro, so
    // it must not name the network (AGENTS.md, network anonymity).
    expect(Conversion::query()->sole()->offer_name)
        ->toBe(ConversionProcessor::PARTNER_OFFER_NAME)
        ->not->toBe('Partner Wall Co');
});

it('writes one conversion when the partner sends the same postback twice', function () {
    Queue::fake();

    $click = embedFixture();

    $dto = new PostbackDTO(
        networkTxId: 'PTX-3',
        clickId: $click->click_id,
        payout: 0.40,
        status: 'credited',
    );

    firePostback($dto)->assertOk();
    firePostback($dto)->assertOk();

    expect(Conversion::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('resolves every publisher macro on a conversion with no offer', function () {
    Queue::fake();

    $click = embedFixture(['postback_url' => 'https://pub.test/cb']);

    firePostback(new PostbackDTO(
        networkTxId: 'PTX-4',
        clickId: $click->click_id,
        payout: 0.40,
        status: 'credited',
        offerName: 'Survey 4 minutes',
    ))->assertOk();

    $url = MacroResolver::resolve(
        'https://pub.test/cb?u={user_id}&o={offer_id}&n={offer_name}&t={trans_id}&p={payout_usd}',
        Conversion::query()->sole(),
        OutgoingPostbackKind::Credit,
    );

    // `{offer_id}` reaches through a null relation and resolves to empty. It
    // must not throw, and it must not leave the macro un-substituted — a
    // publisher parsing that URL would read `{offer_id}` as an offer.
    expect($url)->toContain('o=&')
        ->and($url)->toContain('n=Survey%204%20minutes')
        ->and($url)->not->toContain('{');
});

it('credits a hosted-wall postback that replaced our click id with the partner\'s', function () {
    Queue::fake();

    // MyChips keeps `{sub_id}` on `micro_reward` and overwrites `click_id` (and
    // drops `aff_sub1`) on the real offer. The user_id is the only echo left.
    $click = embedFixture(
        app: ['currency_rate' => 350],
        network: ['hosts_wall' => true],
    );

    firePostback(new PostbackDTO(
        networkTxId: '4e02319d9889b5ab9dcd338a30a6cab1:Default:2619244:0.04',
        clickId: '4e02319d9889b5ab9dcd338a30a6cab1',
        payout: 0.04,
        status: 'credited',
        offerName: 'Default',
        endUserId: 'USER_1',
    ))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Approved)
        ->and($conversion->offer_click_id)->toBe($click->id)
        ->and($conversion->click_id)->toBe($click->click_id)
        ->and($conversion->offer_name)->toBe('Default')
        ->and($conversion->revenue)->toBe('0.040000')
        ->and($conversion->payout)->toBe('0.028000')
        ->and($conversion->currency_amount)->toBe('9.8000')
        ->and(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::Accepted);
});

it('does not guess an embed row when the same user is on two apps', function () {
    Queue::fake();

    $first = embedFixture(network: ['hosts_wall' => true]);
    $otherApp = App::factory()->create();

    OfferClickFactory::new()->create([
        'app_id' => $otherApp->id,
        'publisher_id' => $otherApp->publisher_id,
        'offer_id' => null,
        'network_id' => $first->network_id,
        'end_user_id' => 'USER_1',
    ]);

    firePostback(new PostbackDTO(
        networkTxId: 'PTX-AMBIG',
        clickId: '4e02319d9889b5ab9dcd338a30a6cab1',
        payout: 0.04,
        status: 'credited',
        endUserId: 'USER_1',
    ))->assertOk();

    expect(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::UnknownClick)
        ->and(Conversion::query()->count())->toBe(0);
});

it('does not attach a hosted-wall miss to a catalogue click for the same user', function () {
    Queue::fake();

    $click = moneyFixture();

    $click->network->update(['hosts_wall' => true]);

    firePostback(new PostbackDTO(
        networkTxId: 'PTX-CATALOGUE',
        clickId: '4e02319d9889b5ab9dcd338a30a6cab1',
        payout: 0.04,
        status: 'credited',
        endUserId: $click->end_user_id,
    ))->assertOk();

    expect(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::UnknownClick)
        ->and(Conversion::query()->count())->toBe(0);
});
