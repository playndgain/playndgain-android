<?php

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethodType;
use App\Enums\PublisherStatus;
use App\Enums\WithdrawalStatus;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Money\WithdrawalService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The payout rules from docs/06 §4, asserted at the service rather than through
 * a form: both panels call this class, so this is where "one open request" and
 * "no ledger entry until processing" actually have to hold.
 */
function payableFixture(string $cleared = '100.000000', array $publisher = []): Publisher
{
    $owner = Publisher::factory()->create(['status' => PublisherStatus::Approved, ...$publisher]);

    // Written straight to the table: this suite is about the withdrawal rules,
    // not about how the earning got there.
    LedgerEntry::create([
        'publisher_id' => $owner->id,
        'type' => LedgerEntryType::Earning,
        'amount' => $cleared,
        'balance_after' => $cleared,
        'available_at' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);

    $owner->update(['balance' => $cleared, 'lifetime_earnings' => $cleared]);

    PaymentMethod::create([
        'publisher_id' => $owner->id,
        'type' => PaymentMethodType::Paypal,
        'details' => ['email' => 'payee@example.com'],
        'is_default' => true,
    ]);

    return $owner->fresh();
}

function methodFor(Publisher $publisher): PaymentMethod
{
    return PaymentMethod::query()->withoutGlobalScopes()->where('publisher_id', $publisher->id)->firstOrFail();
}

it('drops withdrawable balance the moment a request opens, and leaves cleared alone', function () {
    $publisher = payableFixture('100.000000');

    expect($publisher->clearedBalance())->toBe('100.000000')
        ->and($publisher->withdrawableBalance())->toBe('100.000000');

    app(WithdrawalService::class)->request($publisher, '40.00', methodFor($publisher));

    $publisher = $publisher->fresh();

    // This is the case that would otherwise let a publisher request the same
    // money twice.
    expect($publisher->clearedBalance())->toBe('100.000000')
        ->and($publisher->withdrawableBalance())->toBe('60.000000');
});

it('refuses a second request while one is open', function () {
    $publisher = payableFixture();
    $service = app(WithdrawalService::class);

    $service->request($publisher, '30.00', methodFor($publisher));

    expect($service->blockedReason($publisher->fresh()))->toContain('open withdrawal request');

    $service->request($publisher->fresh(), '30.00', methodFor($publisher));
})->throws(RuntimeException::class);

it('refuses an amount above the withdrawable balance', function () {
    $publisher = payableFixture('50.000000');

    app(WithdrawalService::class)->request($publisher, '80.00', methodFor($publisher));
})->throws(RuntimeException::class, 'exceeds your available balance');

it('refuses an amount below the minimum payout', function () {
    $publisher = payableFixture();

    app(WithdrawalService::class)->request($publisher, '5.00', methodFor($publisher));
})->throws(RuntimeException::class, 'below the minimum payout');

it('blocks a suspended publisher with the reason', function () {
    $publisher = payableFixture('100.000000', ['status' => PublisherStatus::Suspended]);

    expect(app(WithdrawalService::class)->blockedReason($publisher))->toContain('suspended');
});

it('writes exactly one negative entry on approve and none on reject', function () {
    $publisher = payableFixture();
    $service = app(WithdrawalService::class);
    $admin = User::factory()->admin()->create();

    $withdrawal = $service->request($publisher, '40.00', methodFor($publisher));

    // Rejected from `requested`: nothing was ever debited, so nothing unwinds.
    $service->reject($withdrawal, 'not this time', $admin);

    expect(LedgerEntry::query()->withoutGlobalScopes()
        ->where('publisher_id', $publisher->id)
        ->where('type', LedgerEntryType::Withdrawal)
        ->count())->toBe(0);

    $second = $service->request($publisher->fresh(), '40.00', methodFor($publisher));
    $service->approve($second, $admin);

    $entries = LedgerEntry::query()->withoutGlobalScopes()
        ->where('publisher_id', $publisher->id)
        ->where('type', LedgerEntryType::Withdrawal)
        ->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->amount)->toBe('-40.000000')
        ->and($publisher->fresh()->balance)->toBe('60.000000');
});

it('returns the money with an adjustment when a processing withdrawal is rejected', function () {
    $publisher = payableFixture();
    $service = app(WithdrawalService::class);
    $admin = User::factory()->admin()->create();

    $withdrawal = $service->request($publisher, '40.00', methodFor($publisher));
    $service->approve($withdrawal, $admin);

    expect($publisher->fresh()->balance)->toBe('60.000000');

    $service->reject($withdrawal->fresh(), 'payment bounced', $admin);

    // The negative entry already left. History is never edited, so the money
    // comes back as a compensating adjustment.
    expect($publisher->fresh()->balance)->toBe('100.000000')
        ->and(LedgerEntry::query()->withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('type', LedgerEntryType::Adjustment)
            ->count())->toBe(1);
});

it('snapshots the destination so editing the payment method later does not move it', function () {
    $publisher = payableFixture();
    $method = methodFor($publisher);

    $withdrawal = app(WithdrawalService::class)->request($publisher, '40.00', $method);

    $method->update(['details' => ['email' => 'somewhere-else@example.com']]);

    $snapshot = Withdrawal::query()->withoutGlobalScopes()->findOrFail($withdrawal->id)->destination();

    expect($snapshot['email'])->toBe('payee@example.com');
});

it('reads a snapshot written before the array cast', function () {
    $publisher = payableFixture();
    $method = methodFor($publisher);

    // The old `encrypted` cast stored encryptString(json_encode($details));
    // `encrypted:array` must read that row unchanged.
    $id = DB::table('withdrawals')->insertGetId([
        'publisher_id' => $publisher->id,
        'payment_method_id' => $method->id,
        'amount' => '40.000000',
        'method' => 'paypal',
        'payment_details' => Crypt::encryptString(json_encode(['email' => 'legacy@example.com'])),
        'status' => 'paid',
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Withdrawal::query()->withoutGlobalScopes()->findOrFail($id)->destination())->toBe(['email' => 'legacy@example.com']);
});

it('stops holding a payout out of the available balance once it is debited', function () {
    $publisher = payableFixture('100.000000');
    $service = app(WithdrawalService::class);
    $admin = User::factory()->admin()->create();

    $withdrawal = $service->request($publisher, '40.00', methodFor($publisher));

    expect($publisher->fresh()->withdrawableBalance())->toBe('60.000000');

    // `processing` writes the ledger entry; cleared drops to 60 and the
    // request must not be subtracted a second time.
    $service->approve($withdrawal, $admin);
    $publisher = $publisher->fresh();

    expect($publisher->clearedBalance())->toBe('60.000000')
        ->and($publisher->withdrawableBalance())->toBe('60.000000');
});

it('marks paid only from processing, and records the reference', function () {
    $publisher = payableFixture();
    $service = app(WithdrawalService::class);
    $admin = User::factory()->admin()->create();

    $withdrawal = $service->request($publisher, '40.00', methodFor($publisher));

    expect(fn () => $service->markPaid($withdrawal, 'TX-1', $admin))->toThrow(RuntimeException::class);

    $service->approve($withdrawal, $admin);
    $service->markPaid($withdrawal->fresh(), 'TX-1', $admin);

    $withdrawal = $withdrawal->fresh();

    expect($withdrawal->status)->toBe(WithdrawalStatus::Paid)
        ->and($withdrawal->tx_reference)->toBe('TX-1');
});

it('refuses to approve a payout larger than the cleared balance', function () {
    $publisher = payableFixture('100.000000');
    $service = app(WithdrawalService::class);
    $admin = User::factory()->admin()->create();

    $withdrawal = $service->request($publisher, '100.00', methodFor($publisher));

    // Chargebacks after the request took the balance below the frozen amount.
    LedgerEntry::create([
        'publisher_id' => $publisher->id,
        'type' => LedgerEntryType::Chargeback,
        'amount' => '-30.000000',
        'balance_after' => '70.000000',
        'created_at' => now(),
    ]);
    $publisher->update(['balance' => '70.000000']);

    expect(fn () => $service->approve($withdrawal, $admin))->toThrow(RuntimeException::class);

    expect(LedgerEntry::query()->withoutGlobalScopes()->where('type', LedgerEntryType::Withdrawal)->count())->toBe(0)
        ->and($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Requested);
});
