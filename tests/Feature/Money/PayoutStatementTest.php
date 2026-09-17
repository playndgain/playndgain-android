<?php

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethodType;
use App\Enums\PayoutStatementStatus;
use App\Enums\PublisherStatus;
use App\Enums\WithdrawalStatus;
use App\Mail\MonthlyPayoutSkippedMail;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\PayoutStatement;
use App\Models\Publisher;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Invoices\CreditNoteBuilder;
use App\Services\Money\Money;
use App\Services\Money\PayoutStatementService;
use App\Services\Money\WithdrawalService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Monthly statements — docs/06 §9. Ledger rows are written straight to the
 * table with explicit dates: this suite is about which month a row belongs
 * to and what the statement does with it, not about how it got there.
 */

/**
 * @param  list<array{type: LedgerEntryType, amount: string, at: string, reference?: Withdrawal, description?: string}>  $entries
 */
function statementFixture(array $entries, array $publisher = [], bool $withMethod = true): Publisher
{
    $owner = Publisher::factory()->create(['status' => PublisherStatus::Approved, ...$publisher]);
    $balance = '0.000000';

    foreach ($entries as $entry) {
        $balance = bcadd($balance, $entry['amount'], Money::SCALE);
        $reference = $entry['reference'] ?? null;

        LedgerEntry::create([
            'publisher_id' => $owner->id,
            'type' => $entry['type'],
            'amount' => $entry['amount'],
            'balance_after' => $balance,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'description' => $entry['description'] ?? null,
            'available_at' => $entry['type'] === LedgerEntryType::Earning ? now()->subMinute() : null,
            'created_at' => $entry['at'],
        ]);
    }

    $owner->update(['balance' => $balance, 'lifetime_earnings' => $balance]);

    if ($withMethod) {
        PaymentMethod::create([
            'publisher_id' => $owner->id,
            'type' => PaymentMethodType::Crypto,
            'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'TXk9YbcAbCdEfGhIjKlMnOpQrStUvWxYz1'],
            'is_default' => true,
        ]);
    }

    return $owner->fresh();
}

function statementFor(Publisher $publisher, string $period): ?PayoutStatement
{
    return PayoutStatement::query()
        ->withoutGlobalScopes()
        ->where('publisher_id', $publisher->id)
        ->where('period', $period)
        ->first();
}

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-09-01 00:10:00');
});

it('buckets a month of ledger activity by created_at and leaves payout traffic out', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '12.500000', 'at' => '2026-07-30 12:00:00'],
        ['type' => LedgerEntryType::Earning, 'amount' => '100.000000', 'at' => '2026-08-01 00:00:00'],
        ['type' => LedgerEntryType::Earning, 'amount' => '44.950000', 'at' => '2026-08-20 09:30:00'],
        ['type' => LedgerEntryType::Chargeback, 'amount' => '-7.490000', 'at' => '2026-08-25 18:00:00'],
        ['type' => LedgerEntryType::Adjustment, 'amount' => '2.000000', 'at' => '2026-08-26 10:00:00', 'description' => 'Goodwill'],
        ['type' => LedgerEntryType::Adjustment, 'amount' => '30.000000', 'at' => '2026-08-27 10:00:00', 'description' => 'Rejected withdrawal #9 returned to balance'],
        ['type' => LedgerEntryType::Withdrawal, 'amount' => '-30.000000', 'at' => '2026-08-28 10:00:00'],
        ['type' => LedgerEntryType::Earning, 'amount' => '5.000000', 'at' => '2026-09-01 00:05:00'],
    ]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    $statement = statementFor($publisher, '2026-08-01');

    expect($statement)->not->toBeNull()
        ->and($statement->gross)->toBe('144.950000')
        ->and($statement->chargebacks)->toBe('-7.490000')
        ->and($statement->adjustments)->toBe('2.000000')
        ->and($statement->net)->toBe('139.460000')
        ->and($statement->due_date->toDateString())->toBe('2026-09-30')
        ->and($statement->status)->toBe(PayoutStatementStatus::Pending)
        ->and($statement->withdrawal_id)->not->toBeNull()
        ->and(statementFor($publisher, '2026-07-01'))->toBeNull()
        ->and(statementFor($publisher, '2026-09-01'))->toBeNull();
});

it('freezes the statement and opens exactly one withdrawal on a rerun', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '80.000000', 'at' => '2026-08-10 12:00:00'],
    ]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    // A late entry landing after the run does not move the statement.
    LedgerEntry::create([
        'publisher_id' => $publisher->id,
        'type' => LedgerEntryType::Earning,
        'amount' => '1.000000',
        'balance_after' => '81.000000',
        'available_at' => now()->subMinute(),
        'created_at' => '2026-08-31 23:59:00',
    ]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    expect(statementFor($publisher, '2026-08-01')->gross)->toBe('80.000000')
        ->and(Withdrawal::query()->where('publisher_id', $publisher->id)->count())->toBe(1)
        ->and(PayoutStatement::query()->withoutGlobalScopes()->where('publisher_id', $publisher->id)->count())->toBe(1);
});

it('puts the due date on the last day of the following month', function () {
    expect(PayoutStatementService::dueDate(new DateTimeImmutable('2026-08-01'))->toDateString())->toBe('2026-09-30')
        ->and(PayoutStatementService::dueDate(new DateTimeImmutable('2027-01-15'))->toDateString())->toBe('2027-02-28')
        ->and(PayoutStatementService::dueDate(new DateTimeImmutable('2028-01-01'))->toDateString())->toBe('2028-02-29');
});

it('carries a month below the minimum and settles it with the next payout', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '12.000000', 'at' => '2026-08-10 12:00:00'],
    ]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    $august = statementFor($publisher, '2026-08-01');

    expect($august->status)->toBe(PayoutStatementStatus::Carried)
        ->and($august->withdrawal_id)->toBeNull()
        ->and($august->note)->toMatch('/minimum/i')
        ->and(Withdrawal::query()->count())->toBe(0);

    Mail::assertQueued(MonthlyPayoutSkippedMail::class);

    // September earns enough for both months together, and one cleared
    // entry lands in the ten minutes between month end and the run — money
    // no statement describes yet.
    LedgerEntry::create([
        'publisher_id' => $publisher->id,
        'type' => LedgerEntryType::Earning,
        'amount' => '30.000000',
        'balance_after' => '42.000000',
        'available_at' => '2026-10-01 00:00:00',
        'created_at' => '2026-09-15 12:00:00',
    ]);
    LedgerEntry::create([
        'publisher_id' => $publisher->id,
        'type' => LedgerEntryType::Adjustment,
        'amount' => '3.000000',
        'balance_after' => '45.000000',
        'available_at' => null,
        'created_at' => '2026-10-01 00:05:00',
    ]);
    $publisher->update(['balance' => '45.000000']);

    $this->travelTo('2026-10-01 00:10:00');
    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    $withdrawal = Withdrawal::query()->where('publisher_id', $publisher->id)->sole();
    $august = statementFor($publisher, '2026-08-01');
    $september = statementFor($publisher, '2026-09-01');

    expect($withdrawal->amount)->toBe('45.000000')
        ->and($withdrawal->statements()->count())->toBe(2)
        ->and($august->status)->toBe(PayoutStatementStatus::Pending)
        ->and($august->withdrawal_id)->toBe($withdrawal->id)
        ->and($august->note)->toBeNull()
        ->and($september->withdrawal_id)->toBe($withdrawal->id)
        // Only the part the linked statements do not explain is "carried",
        // and only the latest statement of the payout says so.
        ->and($september->carriedIn())->toBe('3.000000')
        ->and($august->carriedIn())->toBeNull();
});

it('carries a month whose chargebacks outweigh its earnings', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '10.000000', 'at' => '2026-08-10 12:00:00'],
        ['type' => LedgerEntryType::Chargeback, 'amount' => '-25.000000', 'at' => '2026-08-12 12:00:00'],
    ]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    $statement = statementFor($publisher, '2026-08-01');

    expect($statement->net)->toBe('-15.000000')
        ->and($statement->status)->toBe(PayoutStatementStatus::Carried)
        ->and($statement->withdrawal_id)->toBeNull()
        ->and(Withdrawal::query()->count())->toBe(0);
});

it('marks statements paid with the withdrawal and hands them back on rejection', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-08-10 12:00:00'],
    ]);
    $admin = User::factory()->admin()->create();
    $service = app(WithdrawalService::class);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();
    $withdrawal = Withdrawal::query()->where('publisher_id', $publisher->id)->sole();

    // Rejected while merely requested: nothing was written, statement returns.
    $service->reject($withdrawal, 'wrong wallet', $admin);
    $statement = statementFor($publisher, '2026-08-01');

    expect($statement->status)->toBe(PayoutStatementStatus::Pending)
        ->and($statement->withdrawal_id)->toBeNull()
        ->and($statement->note)->toContain('wrong wallet');

    // The next run re-settles it on a fresh withdrawal.
    $this->travelTo('2026-10-01 00:10:00');
    $this->artisan('withdrawals:request-monthly')->assertSuccessful();
    $second = Withdrawal::query()->where('publisher_id', $publisher->id)->where('status', WithdrawalStatus::Requested)->sole();

    expect(statementFor($publisher, '2026-08-01')->withdrawal_id)->toBe($second->id);

    // Rejected from processing: the compensating credit is written but is
    // not activity for October's statement.
    $service->approve($second, $admin);
    $service->reject($second->fresh(), 'bank bounced', $admin);

    expect(statementFor($publisher, '2026-08-01')->withdrawal_id)->toBeNull()
        ->and(app(PayoutStatementService::class)->sums($publisher->id, new DateTimeImmutable('2026-10-01'))['adjustments'])->toBe('0.000000');

    // Paid: every linked statement flips in the same step.
    $this->travelTo('2026-11-01 00:10:00');
    $this->artisan('withdrawals:request-monthly')->assertSuccessful();
    $third = Withdrawal::query()->where('publisher_id', $publisher->id)->where('status', WithdrawalStatus::Requested)->sole();
    $service->approve($third, $admin);
    $service->markPaid($third->fresh(), 'TX-1', $admin);

    $statement = statementFor($publisher, '2026-08-01');

    expect($statement->status)->toBe(PayoutStatementStatus::Paid)
        ->and($statement->paid_at)->not->toBeNull()
        ->and($statement->withdrawal_id)->toBe($third->id);
});

it('writes the statement with the reason when the payout is blocked', function () {
    Setting::set('alert_emails', json_encode(['ops@advault.io']));

    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-08-10 12:00:00'],
    ], withMethod: false);

    $suspended = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-08-10 12:00:00'],
    ], ['status' => PublisherStatus::Suspended]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    expect(Withdrawal::query()->count())->toBe(0)
        ->and(statementFor($publisher, '2026-08-01')->status)->toBe(PayoutStatementStatus::Pending)
        ->and(statementFor($publisher, '2026-08-01')->note)->toContain('payment method')
        // Not approved: no request attempted, but the month still exists.
        ->and(statementFor($suspended, '2026-08-01'))->not->toBeNull()
        ->and(statementFor($suspended, '2026-08-01')->withdrawal_id)->toBeNull();

    Mail::assertQueued(MonthlyPayoutSkippedMail::class, fn (MonthlyPayoutSkippedMail $mail): bool => str_contains($mail->reason, 'payment method'));
});

it('backfills statements for historical months and links paid withdrawals', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '50.000000', 'at' => '2026-06-10 12:00:00'],
        ['type' => LedgerEntryType::Earning, 'amount' => '70.000000', 'at' => '2026-07-10 12:00:00'],
        ['type' => LedgerEntryType::Earning, 'amount' => '90.000000', 'at' => '2026-08-10 12:00:00'],
    ]);
    $method = PaymentMethod::query()->withoutGlobalScopes()->where('publisher_id', $publisher->id)->firstOrFail();
    $admin = User::factory()->admin()->create();

    $paid = Withdrawal::create([
        'publisher_id' => $publisher->id,
        'payment_method_id' => $method->id,
        'amount' => '120.000000',
        'method' => PaymentMethodType::Crypto,
        'payment_details' => $method->details,
        'status' => WithdrawalStatus::Paid,
        'tx_reference' => 'TX-OLD',
        'requested_at' => '2026-08-01 00:10:00',
        'processed_by' => $admin->id,
        'processed_at' => '2026-08-02 10:00:00',
        'paid_at' => '2026-08-03 10:00:00',
    ]);

    $this->artisan('payouts:backfill-statements', ['--dry-run' => true])->assertSuccessful();
    expect(PayoutStatement::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->artisan('payouts:backfill-statements')->assertSuccessful();
    $this->artisan('payouts:backfill-statements')->assertSuccessful();

    expect(PayoutStatement::query()->withoutGlobalScopes()->where('publisher_id', $publisher->id)->count())->toBe(3)
        ->and(statementFor($publisher, '2026-06-01')->status)->toBe(PayoutStatementStatus::Paid)
        ->and(statementFor($publisher, '2026-06-01')->withdrawal_id)->toBe($paid->id)
        ->and(statementFor($publisher, '2026-07-01')->status)->toBe(PayoutStatementStatus::Paid)
        ->and(statementFor($publisher, '2026-07-01')->paid_at->toDateString())->toBe('2026-08-03')
        ->and(statementFor($publisher, '2026-08-01')->status)->toBe(PayoutStatementStatus::Pending)
        ->and(statementFor($publisher, '2026-08-01')->withdrawal_id)->toBeNull();
});

it('issues the credit note for the statement period and its due date', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-08-10 12:00:00'],
    ]);
    $admin = User::factory()->admin()->create();
    $service = app(WithdrawalService::class);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();
    $withdrawal = Withdrawal::query()->where('publisher_id', $publisher->id)->sole();
    $service->approve($withdrawal, $admin);
    $paid = $service->markPaid($withdrawal->fresh(), 'TX-9', $admin);

    $note = app(CreditNoteBuilder::class)->fromWithdrawal($paid->fresh());

    expect($note->periodStart)->toBe('2026-08-01')
        ->and($note->periodEnd)->toBe('2026-08-31')
        ->and($note->dueDate)->toBe('2026-09-30');
});

it('falls back to the legacy window for a withdrawal with no statements', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-08-10 12:00:00'],
    ]);
    $method = PaymentMethod::query()->withoutGlobalScopes()->where('publisher_id', $publisher->id)->firstOrFail();
    $admin = User::factory()->admin()->create();
    $service = app(WithdrawalService::class);

    $withdrawal = $service->request($publisher, '60.00', $method);
    $service->approve($withdrawal, $admin);
    $paid = $service->markPaid($withdrawal->fresh(), 'TX-9', $admin);

    $note = app(CreditNoteBuilder::class)->fromWithdrawal($paid->fresh());

    expect($note->periodStart)->toBe('2026-09-01')
        ->and($note->dueDate)->toBe('2026-10-01');
});

it('reconciles statements against their withdrawals and reports drift', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-08-10 12:00:00'],
    ]);
    $admin = User::factory()->admin()->create();
    $service = app(WithdrawalService::class);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();
    $this->artisan('payouts:reconcile-statements')->assertSuccessful();

    $withdrawal = Withdrawal::query()->where('publisher_id', $publisher->id)->sole();
    $service->approve($withdrawal, $admin);
    $service->markPaid($withdrawal->fresh(), 'TX-1', $admin);

    $this->artisan('payouts:reconcile-statements', ['--publisher' => $publisher->pub_id])->assertSuccessful();

    // Hand-corrupt: the statement says paid, the withdrawal says rejected.
    Withdrawal::query()->whereKey($withdrawal->id)->update(['status' => WithdrawalStatus::Rejected->value]);

    $this->artisan('payouts:reconcile-statements')->assertFailed();

    // Not corrected.
    expect(statementFor($publisher, '2026-08-01')->status)->toBe(PayoutStatementStatus::Paid);
});

it('limits the backfill to one publisher', function () {
    $a = statementFixture([['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-07-10 12:00:00']]);
    $b = statementFixture([['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-07-10 12:00:00']]);

    $this->artisan('payouts:backfill-statements', ['--publisher' => $a->pub_id])->assertSuccessful();

    expect(statementFor($a, '2026-07-01'))->not->toBeNull()
        ->and(statementFor($b, '2026-07-01'))->toBeNull();
});

it('displays money with cents and only the decimals that carry a digit', function () {
    expect(Money::display('235.718000'))->toBe('$235.718')
        ->and(Money::display('0'))->toBe('$0.00')
        ->and(Money::display('-57.490000'))->toBe('-$57.49')
        ->and(Money::display('0.003700'))->toBe('$0.0037')
        ->and(Money::display('1234.500000'))->toBe('$1,234.50')
        ->and(Money::dollars('137.260950'))->toBe('$137.26')
        ->and(Money::dollars('-57.490000'))->toBe('-$57.49');
});

it('absorbs late chargebacks on a requested withdrawal and leaves a paid one frozen', function () {
    $publisher = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '80.000000', 'at' => '2026-08-10 12:00:00'],
    ]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    $statement = statementFor($publisher, '2026-08-01');
    $withdrawal = Withdrawal::query()->where('publisher_id', $publisher->id)->sole();

    expect($statement->chargebacks)->toBe('0.000000')
        ->and($statement->gross)->toBe('80.000000')
        ->and($statement->net)->toBe('80.000000');

    LedgerEntry::create([
        'publisher_id' => $publisher->id,
        'type' => LedgerEntryType::Chargeback,
        'amount' => '-15.000000',
        'balance_after' => '65.000000',
        'available_at' => null,
        'created_at' => now()->toDateTimeString(),
    ]);
    $publisher->update(['balance' => '65.000000']);

    app(WithdrawalService::class)->applyChargeback($publisher, '-15.000000');
    app(PayoutStatementService::class)->syncOpenChargebacks($withdrawal->fresh());

    $open = statementFor($publisher, '2026-08-01');

    expect($open->chargebacks)->toBe('-15.000000')
        ->and($open->net)->toBe('65.000000')
        ->and($open->gross)->toBe('80.000000')
        ->and($open->adjustments)->toBe('0.000000')
        ->and($withdrawal->fresh()->amount)->toBe('65.000000');

    $admin = User::factory()->admin()->create();
    $service = app(WithdrawalService::class);
    $service->approve($withdrawal->fresh(), $admin);
    $service->markPaid($withdrawal->fresh(), 'TX-CB', $admin);

    LedgerEntry::create([
        'publisher_id' => $publisher->id,
        'type' => LedgerEntryType::Chargeback,
        'amount' => '-5.000000',
        'balance_after' => '60.000000',
        'available_at' => null,
        'created_at' => now()->toDateTimeString(),
    ]);

    app(PayoutStatementService::class)->syncOpenChargebacks($withdrawal->fresh());

    $paid = statementFor($publisher, '2026-08-01');

    expect($paid->status)->toBe(PayoutStatementStatus::Paid)
        ->and($paid->chargebacks)->toBe('-15.000000')
        ->and($paid->net)->toBe('65.000000')
        ->and($paid->gross)->toBe('80.000000');
});

it('skips a publisher whose saved destination cannot be read, and carries on', function () {
    Setting::set('alert_emails', json_encode(['ops@advault.io']));

    $broken = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-08-10 12:00:00'],
    ]);
    $fine = statementFixture([
        ['type' => LedgerEntryType::Earning, 'amount' => '60.000000', 'at' => '2026-08-10 12:00:00'],
    ]);

    // A wallet encrypted under another APP_KEY: reads as null, never as a destination.
    DB::table('payment_methods')->where('publisher_id', $broken->id)->update([
        'details' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString(json_encode(['address' => 'lost'])),
    ]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    expect(Withdrawal::query()->where('publisher_id', $broken->id)->count())->toBe(0)
        ->and(Withdrawal::query()->where('publisher_id', $fine->id)->count())->toBe(1)
        ->and(statementFor($broken, '2026-08-01')->note)->toContain('cannot be read');

    Mail::assertQueued(MonthlyPayoutSkippedMail::class, fn (MonthlyPayoutSkippedMail $mail): bool => str_contains($mail->reason, 'cannot be read'));
});

it('links pre-system withdrawals with --link-through, natural reach first', function () {
    $admin = User::factory()->admin()->create();

    // Easy cashs: requested on Aug 31 for August itself.
    $inside = statementFixture([['type' => LedgerEntryType::Earning, 'amount' => '268.100000', 'at' => '2026-08-10 12:00:00']]);
    $insideMethod = PaymentMethod::query()->withoutGlobalScopes()->where('publisher_id', $inside->id)->firstOrFail();
    $insideWithdrawal = Withdrawal::create([
        'publisher_id' => $inside->id, 'payment_method_id' => $insideMethod->id, 'amount' => '268.100000',
        'method' => PaymentMethodType::Crypto, 'payment_details' => $insideMethod->details,
        'status' => WithdrawalStatus::Requested, 'requested_at' => '2026-08-31 20:00:00',
    ]);

    // Rewardshub: a manual August payout, then the Sep 1 job payout for the rest.
    $hybrid = statementFixture([['type' => LedgerEntryType::Earning, 'amount' => '1109.120000', 'at' => '2026-08-10 12:00:00']]);
    $hybridMethod = PaymentMethod::query()->withoutGlobalScopes()->where('publisher_id', $hybrid->id)->firstOrFail();
    $manual = Withdrawal::create([
        'publisher_id' => $hybrid->id, 'payment_method_id' => $hybridMethod->id, 'amount' => '29.000000',
        'method' => PaymentMethodType::Crypto, 'payment_details' => $hybridMethod->details,
        'status' => WithdrawalStatus::Paid, 'tx_reference' => 'TX-M', 'requested_at' => '2026-08-19 10:00:00',
        'processed_by' => $admin->id, 'paid_at' => '2026-08-20 10:00:00',
    ]);
    $job = Withdrawal::create([
        'publisher_id' => $hybrid->id, 'payment_method_id' => $hybridMethod->id, 'amount' => '1080.120000',
        'method' => PaymentMethodType::Crypto, 'payment_details' => $hybridMethod->details,
        'status' => WithdrawalStatus::Paid, 'tx_reference' => 'TX-J', 'requested_at' => '2026-09-01 00:10:00',
        'processed_by' => $admin->id, 'paid_at' => '2026-09-02 10:00:00',
    ]);

    // Without the option the Aug 31 request reaches nothing.
    $this->artisan('payouts:backfill-statements', ['--publisher' => $inside->pub_id])->assertSuccessful();
    expect(statementFor($inside, '2026-08-01')->withdrawal_id)->toBeNull();

    $this->artisan('payouts:backfill-statements', ['--link-through' => '2026-08'])->assertSuccessful();

    $insideStatement = statementFor($inside, '2026-08-01');
    $hybridStatement = statementFor($hybrid, '2026-08-01');

    expect($insideStatement->withdrawal_id)->toBe($insideWithdrawal->id)
        ->and($insideStatement->status)->toBe(PayoutStatementStatus::Pending)
        // The job payout, not the manual one, settles August; the $29 is the carry.
        ->and($hybridStatement->withdrawal_id)->toBe($job->id)
        ->and($hybridStatement->status)->toBe(PayoutStatementStatus::Paid)
        ->and($hybridStatement->carriedIn())->toBe('-29.000000')
        ->and($manual->fresh()->statements()->count())->toBe(0);

    $this->artisan('payouts:reconcile-statements')->assertSuccessful();
});
