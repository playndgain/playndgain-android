<?php

use App\Enums\ConversionStatus;
use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\LedgerEntry;
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
