<?php

namespace App\Services\Money;

use App\Enums\PublisherStatus;
use App\Enums\WithdrawalStatus;
use App\Mail\CreditNoteMail;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Invoices\CreditNoteBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

/**
 * The withdrawal state machine — docs/06 §4.
 *
 * requested ──► processing ──► paid
 *      └──► rejected  └──► rejected
 *
 * Both panels drive money through this class rather than through their own
 * forms, because the rules that matter (one open request, snapshot the
 * destination, ledger entry only at `processing`) are the same wherever the
 * click came from.
 */
class WithdrawalService
{
    /** Floor under `publishers.min_payout`. A publisher override cannot go below it. */
    public const FLOOR_MIN_PAYOUT = '20.00';

    public const UNREADABLE_DESTINATION = 'Your saved payment destination cannot be read. Enter it again on the Payments page.';

    /**
     * Why this publisher cannot request right now, or null if they can.
     *
     * Returns the specific reason rather than a boolean: a greyed-out button
     * with no explanation is a support ticket.
     */
    public function blockedReason(Publisher $publisher): ?string
    {
        if ($publisher->status !== PublisherStatus::Approved) {
            return match ($publisher->status) {
                PublisherStatus::Pending => 'Your account is still under review.',
                PublisherStatus::Suspended => 'Your account is suspended. Contact support.',
                default => 'Your account cannot request withdrawals.',
            };
        }

        if ($this->hasOpenRequest($publisher)) {
            return 'You already have an open withdrawal request. One at a time.';
        }

        $available = $publisher->withdrawableBalance();

        if (bccomp($available, '0', Money::SCALE) < 0) {
            return 'Your balance is negative. Future earnings pay it down first.';
        }

        $minimum = $this->minimumPayout($publisher);

        if (bccomp($available, $minimum, Money::SCALE) < 0) {
            return "Minimum payout is \${$minimum}. Your available balance is \${$available}.";
        }

        if ($publisher->paymentMethods()->count() === 0) {
            return 'Add a payment method to receive the monthly payout.';
        }

        return null;
    }

    public function minimumPayout(Publisher $publisher): string
    {
        $configured = Money::format($publisher->min_payout ?? Setting::get('default_min_payout', self::FLOOR_MIN_PAYOUT), 2);

        return bccomp($configured, self::FLOOR_MIN_PAYOUT, 2) > 0 ? $configured : self::FLOOR_MIN_PAYOUT;
    }

    public function hasOpenRequest(Publisher $publisher): bool
    {
        return $publisher->withdrawals()
            ->whereIn('status', [WithdrawalStatus::Requested, WithdrawalStatus::Processing])
            ->exists();
    }

    /**
     * No ledger entry here. The funds are held out of `withdrawableBalance()`
     * instead, so rejecting costs nothing to unwind.
     */
    public function request(Publisher $publisher, string $amount, PaymentMethod $method): Withdrawal
    {
        return DB::transaction(function () use ($publisher, $amount, $method): Withdrawal {
            // Re-read under the lock. Two tabs submitting the same form is the
            // exact case this exists for, and the check above is only advisory.
            $publisher = Publisher::query()->lockForUpdate()->findOrFail($publisher->id);

            if ($this->hasOpenRequest($publisher)) {
                throw new RuntimeException('You already have an open withdrawal request.');
            }

            $amount = Money::format($amount);

            if (bccomp($amount, $publisher->withdrawableBalance(), Money::SCALE) > 0) {
                throw new RuntimeException('Amount exceeds your available balance.');
            }

            if (bccomp($amount, $this->minimumPayout($publisher), Money::SCALE) < 0) {
                throw new RuntimeException('Amount is below the minimum payout.');
            }

            // A destination encrypted under a key we no longer have reads as
            // null (PaymentMethod::fromEncryptedString). Snapshotting nothing
            // would open a payout with nowhere to go.
            $details = $method->getAttribute('details');

            if (! is_array($details) || $details === []) {
                throw new RuntimeException(self::UNREADABLE_DESTINATION);
            }

            $withdrawal = Withdrawal::create([
                'publisher_id' => $publisher->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'method' => $method->type,
                // Snapshot. Editing the payment method later must not move an
                // already-requested payout to a new destination.
                'payment_details' => $method->details,
                'status' => WithdrawalStatus::Requested,
                'requested_at' => now(),
            ]);

            AuditLog::record('withdrawal.requested', $withdrawal, null, ['amount' => $amount]);

            return $withdrawal;
        });
    }

    /**
     * A chargeback landing while a request is still open — docs/06 §4.
     *
     * The amount was frozen when the request opened and no ledger entry backs
     * it, so a reversal that lands afterwards leaves the request promising
     * money the publisher no longer has. Reducing it here is the only place
     * that stays true; `processing` and `paid` are past the point of no
     * automatic clawback (§5).
     *
     * Called from inside the chargeback's own transaction, which already holds
     * the publisher row lock — only the withdrawal needs locking here.
     */
    public function applyChargeback(Publisher $publisher, string $debitAmount, ?User $actor = null): void
    {
        $chargeback = bccomp($debitAmount, '0', Money::SCALE) < 0
            ? Money::negate($debitAmount)
            : Money::format($debitAmount);

        if (bccomp($chargeback, '0', Money::SCALE) <= 0) {
            return;
        }

        $withdrawal = Withdrawal::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('status', WithdrawalStatus::Requested)
            ->lockForUpdate()
            ->first();

        if ($withdrawal === null) {
            return;
        }

        $before = Money::format($withdrawal->amount);

        // Never negative: the excess is a debt the ledger already carries, and
        // repeating it on the withdrawal would double it.
        $after = bccomp($chargeback, $before, Money::SCALE) >= 0
            ? Money::format('0')
            : bcsub($before, $chargeback, Money::SCALE);

        $withdrawal->update(['amount' => $after]);

        AuditLog::record('withdrawal.reduced_by_chargeback', $withdrawal, ['amount' => $before], ['amount' => $after], $actor);

        if (bccomp($after, $this->minimumPayout($publisher), Money::SCALE) >= 0) {
            return;
        }

        // Below the floor the monthly run applies. Left at the reduced amount
        // rather than restored: the row is the record of what the chargebacks
        // did to it. No ledger entry — `requested` was never debited.
        $reason = 'Auto-rejected: chargebacks reduced amount below minimum payout';

        $withdrawal->update([
            'status' => WithdrawalStatus::Rejected,
            'admin_note' => $reason,
            // Null on the automatic path: a postback has nobody to attribute
            // the rejection to, the same convention AuditLog already uses.
            'processed_by' => $actor?->id,
            'processed_at' => now(),
        ]);

        $this->statements()->unlink($withdrawal, $reason);

        AuditLog::record('withdrawal.rejected', $withdrawal, ['status' => 'requested'], ['status' => 'rejected', 'reason' => $reason], $actor);
    }

    /** Approve → `processing`. This is where the money genuinely leaves. */
    public function approve(Withdrawal $withdrawal, User $actor): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $actor): Withdrawal {
            $withdrawal = $this->lockOpen($withdrawal, WithdrawalStatus::Requested);

            // Defense in depth. The amount is frozen at request time and
            // chargebacks since then are not in it, so the only figure that
            // can be trusted at the moment of payment is the cleared balance.
            // Compared against cleared, not withdrawable: this request is
            // still `requested`, so withdrawable already subtracts it.
            $publisher = Publisher::query()->findOrFail($withdrawal->publisher_id);
            $amount = Money::format($withdrawal->amount);

            if (bccomp($amount, $publisher->clearedBalance(), Money::SCALE) > 0) {
                throw new RuntimeException('Amount exceeds the publisher\'s cleared balance.');
            }

            $withdrawal->update([
                'status' => WithdrawalStatus::Processing,
                'processed_by' => $actor->id,
                'processed_at' => now(),
            ]);

            LedgerEntry::createWithdrawal($withdrawal, $actor);

            AuditLog::record('withdrawal.approved', $withdrawal, ['status' => 'requested'], ['status' => 'processing'], $actor);

            return $withdrawal;
        });
    }

    public function markPaid(Withdrawal $withdrawal, string $reference, User $actor): Withdrawal
    {
        $withdrawal = DB::transaction(function () use ($withdrawal, $reference, $actor): Withdrawal {
            $withdrawal = $this->lockOpen($withdrawal, WithdrawalStatus::Processing);

            $withdrawal->update([
                'status' => WithdrawalStatus::Paid,
                'tx_reference' => $reference,
                'paid_at' => now(),
            ]);

            // The statements this payout settles turn `paid` in the same
            // transaction — a publisher must never see a paid withdrawal next
            // to a pending statement for the same month.
            $this->statements()->markPaid($withdrawal);

            AuditLog::record('withdrawal.paid', $withdrawal, ['status' => 'processing'], ['status' => 'paid', 'tx_reference' => $reference], $actor);

            return $withdrawal;
        });

        $this->queueCreditNote($withdrawal->fresh(['publisher.user']) ?? $withdrawal);

        return $withdrawal;
    }

    /**
     * After the money is marked paid, not inside the transaction: a mail
     * failure must not unwind the payout.
     */
    private function queueCreditNote(Withdrawal $withdrawal): void
    {
        $email = $withdrawal->publisher?->user?->email;

        if (! is_string($email) || $email === '') {
            return;
        }

        try {
            $note = app(CreditNoteBuilder::class)->fromWithdrawal($withdrawal);
        } catch (InvalidArgumentException) {
            return;
        }

        Mail::to($email)->queue(new CreditNoteMail($note));
    }

    /**
     * Rejecting from `processing` writes a compensating adjustment: the
     * negative entry already left the balance and the ledger is never edited.
     */
    public function reject(Withdrawal $withdrawal, string $reason, User $actor): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $reason, $actor): Withdrawal {
            $withdrawal = $this->lockRow($withdrawal);

            $wasProcessing = $withdrawal->status === WithdrawalStatus::Processing;

            if (! $wasProcessing && $withdrawal->status !== WithdrawalStatus::Requested) {
                throw new RuntimeException('Only an open withdrawal can be rejected.');
            }

            $before = $withdrawal->status->value;

            $withdrawal->update([
                'status' => WithdrawalStatus::Rejected,
                'admin_note' => $reason,
                'processed_by' => $actor->id,
                'processed_at' => now(),
            ]);

            if ($wasProcessing) {
                // Referenced to the withdrawal so a monthly statement can tell
                // this credit apart from a genuine correction and leave it
                // out of the month's activity.
                LedgerEntry::createAdjustment(
                    Publisher::query()->findOrFail($withdrawal->publisher_id),
                    Money::format($withdrawal->amount),
                    "Rejected withdrawal #{$withdrawal->id} returned to balance",
                    $actor,
                    $withdrawal,
                );
            }

            $this->statements()->unlink($withdrawal, $reason);

            AuditLog::record('withdrawal.rejected', $withdrawal, ['status' => $before], ['status' => 'rejected', 'reason' => $reason], $actor);

            return $withdrawal;
        });
    }

    /**
     * Re-read the row under a lock and check its state there.
     *
     * Checking the status on the model the caller passed in is a check-then-act:
     * two admins clicking Approve in the same moment both see `requested`, both
     * proceed, and the publisher is debited twice for one payout. The lock in
     * LedgerEntry::record() does not help — it serialises the two writes rather
     * than preventing the second.
     */
    private function lockOpen(Withdrawal $withdrawal, WithdrawalStatus $expected): Withdrawal
    {
        $locked = $this->lockRow($withdrawal);

        if ($locked->status !== $expected) {
            throw new RuntimeException("Withdrawal is {$locked->status->value}, not {$expected->value}.");
        }

        return $locked;
    }

    /**
     * Resolved lazily: the statement service depends on this one, and a
     * constructor dependency in both directions is a container loop.
     */
    private function statements(): PayoutStatementService
    {
        return app(PayoutStatementService::class);
    }

    private function lockRow(Withdrawal $withdrawal): Withdrawal
    {
        return Withdrawal::query()
            ->withoutGlobalScopes()
            ->whereKey($withdrawal->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
