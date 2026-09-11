<?php

use App\Enums\ConversionStatus;
use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\IpVerdict;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\LedgerEntry;
use App\Models\OfferClick;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/FraudFixtures.php';
require_once __DIR__.'/../Money/MoneyFixtures.php';

beforeEach(fn () => fraudBoot());

it('approves a credited postback when no post-conversion rule fires', function () {
    Queue::fake();

    $click = moneyFixture();
    fraudRule('impossible_speed', FraudStage::PostConversion, FraudAction::Flag, ['min_seconds' => 0], weight: 20);

    firePostback(postbackDto($click))->assertOk();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Approved)
        ->and(FraudEvent::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('holds a flagged conversion at pending with no ledger entry', function () {
    Queue::fake();

    $click = moneyFixture();
    fraudRule('impossible_speed', FraudStage::PostConversion, FraudAction::Flag, ['min_seconds' => 900], weight: 20);

    firePostback(postbackDto($click))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Pending)
        ->and($conversion->rejected_reason)->toBeNull()
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(FraudEvent::query()->sole()->rule_key)->toBe('impossible_speed');
});

it('rejects a blocked conversion and records the rule key as the rejected reason', function () {
    Queue::fake();

    $click = moneyFixture();
    fraudRule('impossible_speed', FraudStage::PostConversion, FraudAction::Block, ['min_seconds' => 900], weight: 20);

    firePostback(postbackDto($click))->assertOk();

    $conversion = Conversion::query()->sole();

    // docs/01 §7: the column carries the rule key, not a generic label. It is
    // internal — Decision::publicReason() is all a publisher ever sees.
    expect($conversion->status)->toBe(ConversionStatus::Rejected)
        ->and($conversion->rejected_reason)->toBe('impossible_speed')
        ->and(LedgerEntry::query()->count())->toBe(0);

    $event = FraudEvent::query()->sole();

    expect($event->rule_key)->toBe('impossible_speed')
        ->and($event->subject_type)->toBe($click->getMorphClass())
        ->and($event->subject_id)->toBe($click->getKey())
        ->and($event->context)->toHaveKeys(['elapsed_seconds', 'min_seconds', 'clicked_at']);
});

it('leaves a network rejection labelled as one', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click, status: 'rejected'))->assertOk();

    expect(Conversion::query()->sole()->rejected_reason)->toBe('network_rejected');
});

it('rejects on the block rule even when a flag rule fires alongside it', function () {
    Queue::fake();

    $click = moneyFixture();
    fraudRule('impossible_speed', FraudStage::PostConversion, FraudAction::Flag, ['min_seconds' => 900], weight: 20);
    fraudRule('user_daily_cap', FraudStage::PostConversion, FraudAction::Block, ['max' => 0], weight: 20);

    firePostback(postbackDto($click))->assertOk();

    expect(Conversion::query()->sole()->rejected_reason)->toBe('user_daily_cap')
        ->and(FraudEvent::query()->pluck('rule_key')->all())
        ->toEqualCanonicalizing(['impossible_speed', 'user_daily_cap']);
});

/**
 * The shared-tracking-URL rules on the real postback path — spec §6A.
 *
 * `conversion_proxy_ip` is the first rule that rejects a conversion on an IP
 * signal, so the two things worth pinning down are that it rejects on the
 * postback's own address rather than the click's, and that a later reversal for
 * the same transaction does not invent a debt out of money nobody was paid.
 */
function proxyPostbackDto(OfferClick $click, string $conversionIp, string $status = 'credited'): PostbackDTO
{
    return new PostbackDTO(
        networkTxId: 'NTX-100',
        clickId: $click->click_id,
        payout: 0.13,
        status: $status,
        offerExternalId: $click->offer->external_id,
        offerName: $click->offer->name,
        endUserId: $click->end_user_id,
        ip: $conversionIp,
    );
}

it('rejects a conversion that arrived from a proxy and pays nobody', function () {
    Queue::fake();

    $click = moneyFixture();
    fakeIpVerdicts(['198.51.100.7' => new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)]);
    fraudRule('conversion_proxy_ip', FraudStage::PostConversion, FraudAction::Block, weight: 45);

    firePostback(proxyPostbackDto($click, '198.51.100.7'))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Rejected)
        ->and($conversion->rejected_reason)->toBe('conversion_proxy_ip')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

/**
 * A conversion this rule rejected never reached the ledger, so a later
 * reversal must move no money — the `(network_id, network_txid)` row still
 * exists and still matches.
 *
 * It also must not move the *status*. Our own rule refused this conversion;
 * a network declining something we had already declined tells us nothing new,
 * and overwriting `rejected` with `reversed` would lose the rule key that says
 * why we refused it. docs/04 §3.
 */
it('leaves a proxy-rejected conversion rejected when the network declines it too', function () {
    Queue::fake();

    $click = moneyFixture();
    fakeIpVerdicts(['198.51.100.7' => new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)]);
    fraudRule('conversion_proxy_ip', FraudStage::PostConversion, FraudAction::Block, weight: 45);

    firePostback(proxyPostbackDto($click, '198.51.100.7'))->assertOk();
    firePostback(proxyPostbackDto($click, '198.51.100.7', status: 'reversed'))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Rejected)
        ->and($conversion->rejected_reason)->toBe('conversion_proxy_ip')
        ->and($conversion->reversed_at)->toBeNull()
        ->and($conversion->upstream_status)->toBe(ConversionStatus::Reversed)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

/** The click's address is not evidence about the conversion — see FraudContext::$conversionIp. */
it('credits a clean conversion even when the click itself came from a proxy', function () {
    Queue::fake();

    $click = moneyFixture();
    fakeIpVerdicts([
        $click->ip => new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn),
        '198.51.100.9' => new IpVerdict(country: 'US', asn: 7922),
    ]);
    fraudRule('conversion_proxy_ip', FraudStage::PostConversion, FraudAction::Block, weight: 45);

    firePostback(proxyPostbackDto($click, '198.51.100.9'))->assertOk();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Approved)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('runs the fraud pipeline once per postback, not twice', function () {
    Queue::fake();

    fraudRule('impossible_speed', FraudStage::PostConversion, FraudAction::Flag, weight: 10);

    $click = moneyFixture();
    // Instant conversion: the rule fires on every evaluation, so the number of
    // events is the number of times the pipeline ran.
    $click->forceFill(['created_at' => now()])->save();

    // A `pending` postback never reaches the pipeline — only a credit does.
    firePostback(postbackDto($click, status: 'pending'));

    expect(FraudEvent::query()->count())->toBe(0);

    // One inbound postback, one evaluation. The existence check in `record()`
    // is what keeps the create path's run off a transaction that was only ever
    // going to collide; the reconciler's run is the one with the existing
    // conversion in its context, and it is the only one that happens.
    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    expect(FraudEvent::query()->count())->toBe(1)
        // Subject is the conversion, not the click: on this path the row exists.
        ->and(FraudEvent::query()->sole()->subject_type)->toBe((new Conversion)->getMorphClass())
        // Flagged, so it stays in the review queue rather than being paid.
        ->and(Conversion::query()->sole()->status)->toBe(ConversionStatus::Pending)
        ->and(Conversion::query()->sole()->upstream_status)->toBe(ConversionStatus::Approved)
        ->and(LedgerEntry::query()->count())->toBe(0);
});
