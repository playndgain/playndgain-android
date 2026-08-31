<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\LedgerEntryType;
use App\Enums\OutgoingPostbackKind;
use App\Jobs\DispatchPublisherPostback;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

it('returns the balance to its pre-conversion value on a chargeback', function () {
    Queue::fake();

    $click = moneyFixture();
    $before = $click->publisher->fresh()->balance;

    firePostback(postbackDto($click))->assertOk();

    expect($click->publisher->fresh()->balance)->toBe('0.091000');

    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    $conversion = Conversion::query()->sole();
    $chargeback = LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->sole();

    expect($conversion->status)->toBe(ConversionStatus::Reversed)
        ->and($conversion->reversed_at)->not->toBeNull()
        ->and($chargeback->amount)->toBe('-0.091000')
        ->and($chargeback->balance_after)->toBe('0.000000')
        // Chargebacks clear immediately — a reversal has to reduce what is
        // withdrawable now, not in thirty days.
        ->and($chargeback->available_at)->toBeNull()
        ->and($click->publisher->fresh()->balance)->toBe($before)
        // Lifetime earnings are a vanity number and ignore reversals.
        ->and($click->publisher->fresh()->lifetime_earnings)->toBe('0.091000');
});

it('treats a repeated reversal as the retry it is', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();
    firePostback(postbackDto($click, status: 'reversed'))->assertOk();
    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    expect(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and($click->publisher->fresh()->balance)->toBe('0.000000')
        ->and(IncomingPostback::query()->orderBy('id')->get()->last()->result)
        ->toBe(IncomingPostbackResult::Duplicate);
});

it('fires a second postback carrying the original transaction id', function () {
    Queue::fake();

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb?tx={trans_id}&payout={payout_usd}']);

    firePostback(postbackDto($click))->assertOk();
    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    $conversion = Conversion::query()->sole();

    Queue::assertPushed(DispatchPublisherPostback::class, 2);
    Queue::assertPushed(
        DispatchPublisherPostback::class,
        fn (DispatchPublisherPostback $job) => $job->kind === OutgoingPostbackKind::Reversal
            && $job->conversion->trans_id === $conversion->trans_id
    );
});

it('refuses to charge back a conversion that never credited anyone', function () {
    Queue::fake();

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb?tx={trans_id}']);

    firePostback(postbackDto($click, status: 'pending'))->assertOk();
    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Reversed)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($click->publisher->fresh()->balance)->toBe('0.000000');

    Queue::assertNothingPushed();
});

it('logs a reversal for a transaction it has never seen', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click, status: 'reversed', txId: 'NTX-NEVER-SEEN'))->assertOk();

    expect(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::Error)
        ->and(Conversion::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('lets the balance go negative when a reversal lands after a withdrawal', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    // The money already left: a withdrawal drained the cached balance and the
    // ledger. Future earnings pay the negative down; we do not claw back.
    LedgerEntry::query()->create([
        'publisher_id' => $click->publisher_id,
        'type' => LedgerEntryType::Withdrawal,
        'amount' => '-0.091000',
        'balance_after' => '0.000000',
        'created_at' => now(),
    ]);
    $click->publisher->update(['balance' => '0.000000']);

    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    expect($click->publisher->fresh()->balance)->toBe('-0.091000');
});

it('debits a chargeback immediately while the earning is still held', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-01-15 14:30:00', 'UTC'));

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();
    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    $publisher = $click->publisher->fresh();

    expect($publisher->pendingBalance())->toBe('0.091000')
        ->and($publisher->withdrawableBalance())->toBe('-0.091000');
});
