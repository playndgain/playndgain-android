<?php

use App\Enums\PaymentMethodType;
use App\Enums\WithdrawalStatus;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Money\WithdrawalService;

/**
 * Every state transition moves money, so each one re-reads its row under a lock
 * before acting. Checking the status on the model the caller happens to hold is
 * a check-then-act, and the failure is a publisher debited twice for one payout.
 */
function openWithdrawal(string $amount = '50.000000'): Withdrawal
{
    $publisher = Publisher::factory()->create();

    return $publisher->withdrawals()->create([
        'payment_method_id' => PaymentMethod::query()->create([
            'publisher_id' => $publisher->id,
            'type' => PaymentMethodType::Paypal,
            'details' => ['email' => 'pub@example.test'],
            'is_default' => true,
        ])->id,
        'amount' => $amount,
        'method' => PaymentMethodType::Paypal,
        'payment_details' => json_encode(['email' => 'pub@example.test']),
        'status' => WithdrawalStatus::Requested,
        'requested_at' => now(),
    ]);
}

it('approves a withdrawal exactly once', function () {
    $withdrawal = openWithdrawal();
    $admin = User::factory()->admin()->create();
    $service = app(WithdrawalService::class);

    $service->approve($withdrawal, $admin);

    // The second click carries the stale model the first one was approved from,
    // which is precisely the state a real double-submit arrives in.
    expect(fn () => $service->approve($withdrawal, $admin))->toThrow(RuntimeException::class);

    expect(LedgerEntry::query()->withoutGlobalScopes()->where('type', 'withdrawal')->count())->toBe(1);
});

it('rejects a processing withdrawal exactly once, returning the money once', function () {
    $withdrawal = openWithdrawal();
    $admin = User::factory()->admin()->create();
    $service = app(WithdrawalService::class);

    $service->approve($withdrawal, $admin);
    $service->reject($withdrawal, 'wrong destination', $admin);

    expect(fn () => $service->reject($withdrawal, 'again', $admin))->toThrow(RuntimeException::class);

    // One debit, one compensating credit. The ledger is never edited.
    expect(LedgerEntry::query()->withoutGlobalScopes()->where('type', 'withdrawal')->count())->toBe(1)
        ->and(LedgerEntry::query()->withoutGlobalScopes()->where('type', 'adjustment')->count())->toBe(1);
});

it('will not mark a withdrawal paid twice', function () {
    $withdrawal = openWithdrawal();
    $admin = User::factory()->admin()->create();
    $service = app(WithdrawalService::class);

    $service->approve($withdrawal, $admin);
    $service->markPaid($withdrawal, 'TX-1', $admin);

    expect(fn () => $service->markPaid($withdrawal, 'TX-2', $admin))->toThrow(RuntimeException::class);

    expect($withdrawal->fresh()->tx_reference)->toBe('TX-1');
});
