<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\LedgerEntryType;
use App\Enums\OutgoingPostbackKind;
use App\Enums\PaymentMethodType;
use App\Enums\WithdrawalStatus;
use App\Jobs\DispatchPublisherPostback;
use App\Models\AuditLog;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\OfferClick;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Money\ConversionProcessor;
use App\Services\Money\WithdrawalService;
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

    $conversion = Conversion::query()->sole();

    // D2: a decline that arrives before anyone was credited is a rejection, not
    // a reversal. The money outcome is identical — no ledger entry, no
    // chargeback, no publisher callback — and `reversed` on a row nobody was
    // paid for reads in the panels and the exports as a chargeback that never
    // happened. docs/04 §3 carries the matrix.
    expect($conversion->status)->toBe(ConversionStatus::Rejected)
        ->and($conversion->rejected_reason)->toBe('network_rejected')
        ->and($conversion->upstream_status)->toBe(ConversionStatus::Reversed)
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

/**
 * docs/06 §4: a chargeback that lands while a request is still open reduces
 * that request. The amount was frozen at request time, so without this the
 * admin approves a payout larger than the publisher's cleared balance.
 *
 * @param  array<string, mixed>  $publisher
 */
function reversibleWithOpenRequest(string $requested = '100.000000', string $cleared = '100.000000', array $publisher = []): OfferClick
{
    $click = moneyFixture(publisher: ['rev_share' => '100.00', ...$publisher]);

    LedgerEntry::create([
        'publisher_id' => $click->publisher_id,
        'type' => LedgerEntryType::Earning,
        'amount' => $cleared,
        'balance_after' => $cleared,
        'available_at' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);

    $click->publisher->update(['balance' => $cleared, 'lifetime_earnings' => $cleared]);

    $method = PaymentMethod::create([
        'publisher_id' => $click->publisher_id,
        'type' => PaymentMethodType::Paypal,
        'details' => ['email' => 'payee@example.com'],
        'is_default' => true,
    ]);

    Withdrawal::create([
        'publisher_id' => $click->publisher_id,
        'payment_method_id' => $method->id,
        'amount' => $requested,
        'method' => PaymentMethodType::Paypal,
        'payment_details' => $method->details,
        'status' => WithdrawalStatus::Requested,
        'requested_at' => now(),
    ]);

    return $click;
}

function openRequestOf(OfferClick $click): Withdrawal
{
    return Withdrawal::query()->withoutGlobalScopes()->where('publisher_id', $click->publisher_id)->sole();
}

it('reduces an open requested withdrawal by the chargeback', function () {
    Queue::fake();

    $click = reversibleWithOpenRequest();

    firePostback(postbackDto($click, payout: 10.0))->assertOk();
    firePostback(postbackDto($click, status: 'reversed', payout: 10.0))->assertOk();

    $withdrawal = openRequestOf($click);
    $publisher = $click->publisher->fresh();

    expect($withdrawal->amount)->toBe('90.000000')
        ->and($withdrawal->status)->toBe(WithdrawalStatus::Requested)
        ->and(LedgerEntry::query()->withoutGlobalScopes()->where('type', LedgerEntryType::Chargeback)->sole()->amount)->toBe('-10.000000')
        // The chargeback clears immediately while the reversed earning is
        // still held, so cleared drops by it and the reduced request holds
        // back exactly what is left.
        ->and($publisher->clearedBalance())->toBe('90.000000')
        ->and($publisher->withdrawableBalance())->toBe('0.000000');
});

it('reduces the same open withdrawal for each chargeback in turn', function () {
    Queue::fake();

    $click = reversibleWithOpenRequest();

    firePostback(postbackDto($click, payout: 10.0, txId: 'NTX-1'))->assertOk();
    firePostback(postbackDto($click, payout: 5.0, txId: 'NTX-2'))->assertOk();
    firePostback(postbackDto($click, status: 'reversed', payout: 10.0, txId: 'NTX-1'))->assertOk();
    firePostback(postbackDto($click, status: 'reversed', payout: 5.0, txId: 'NTX-2'))->assertOk();

    expect(openRequestOf($click)->amount)->toBe('85.000000');
});

it('empties and auto-rejects a request the chargeback outgrows', function () {
    Queue::fake();

    $click = reversibleWithOpenRequest(requested: '30.000000', cleared: '200.000000');

    firePostback(postbackDto($click, payout: 40.0))->assertOk();
    firePostback(postbackDto($click, status: 'reversed', payout: 40.0))->assertOk();

    $withdrawal = openRequestOf($click);
    $publisher = $click->publisher->fresh();

    expect($withdrawal->amount)->toBe('0.000000')
        ->and($withdrawal->status)->toBe(WithdrawalStatus::Rejected)
        ->and($withdrawal->admin_note)->toContain('minimum payout')
        // The reduction moves no money: the full chargeback is in the ledger.
        ->and($publisher->clearedBalance())->toBe('160.000000')
        // Nothing is held back any more, so available is the cleared figure.
        ->and($publisher->withdrawableBalance())->toBe('160.000000');
});

it('auto-rejects a request the chargeback drags under the minimum payout', function () {
    Queue::fake();

    $click = reversibleWithOpenRequest(requested: '25.000000');

    firePostback(postbackDto($click, payout: 10.0))->assertOk();
    firePostback(postbackDto($click, status: 'reversed', payout: 10.0))->assertOk();

    $withdrawal = openRequestOf($click);

    // Left at the reduced amount for the audit trail, but rejected: a $15
    // payout would break the same floor the monthly run applies.
    expect($withdrawal->amount)->toBe('15.000000')
        ->and($withdrawal->status)->toBe(WithdrawalStatus::Rejected);
});

it('leaves a processing withdrawal alone and lets the balance go negative', function () {
    Queue::fake();

    $click = reversibleWithOpenRequest();
    $admin = User::factory()->admin()->create();
    $withdrawal = openRequestOf($click);

    firePostback(postbackDto($click, payout: 10.0))->assertOk();
    app(WithdrawalService::class)->approve($withdrawal, $admin);

    firePostback(postbackDto($click, status: 'reversed', payout: 10.0))->assertOk();

    // docs/06 §5: no automatic clawback once the money has left.
    expect(openRequestOf($click)->amount)->toBe('100.000000')
        ->and(openRequestOf($click)->status)->toBe(WithdrawalStatus::Processing)
        ->and($click->publisher->fresh()->balance)->toBe('0.000000')
        // The reversed earning is still inside its hold window, so the debit
        // lands on a cleared balance that has nothing left to absorb it.
        ->and($click->publisher->fresh()->clearedBalance())->toBe('-10.000000');
});

it('reduces the open request on an admin-forced reversal too', function () {
    Queue::fake();

    $click = reversibleWithOpenRequest();
    $admin = User::factory()->admin()->create();

    firePostback(postbackDto($click, payout: 10.0))->assertOk();

    $this->actingAs($admin);
    app(ConversionProcessor::class)->forceReverse(Conversion::query()->withoutGlobalScopes()->sole(), 'advertiser refund', $admin);

    expect(openRequestOf($click)->amount)->toBe('90.000000')
        ->and(AuditLog::query()->where('action', 'withdrawal.reduced_by_chargeback')->sole())
        ->before->toBe(['amount' => '100.000000'])
        ->after->toBe(['amount' => '90.000000']);
});
