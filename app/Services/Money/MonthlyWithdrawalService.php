<?php

namespace App\Services\Money;

use App\Enums\PublisherStatus;
use App\Mail\MonthlyPayoutErrorsMail;
use App\Mail\MonthlyPayoutSkippedMail;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use App\Support\AdminAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * End-of-month run — docs/06 §9.
 *
 * First the previous month's statement is written for every publisher with
 * ledger activity, then one withdrawal per approved publisher is opened on
 * their default payment method for the full withdrawable balance and linked
 * to every statement it settles.
 *
 * Publishers who fail the payout rules are not requested. They get a skip
 * mail; ops get one error mail listing every failure for the run; and the
 * reason is written on the statement so the Payments page can show it.
 */
final class MonthlyWithdrawalService
{
    public function __construct(
        private WithdrawalService $withdrawals,
        private PayoutStatementService $statements,
    ) {}

    /**
     * @return array{requested: int, skipped: int, statements: int}
     */
    public function requestDue(): array
    {
        $period = CarbonImmutable::now()->startOfMonth()->subMonth();
        $statements = $this->statements->generateForPeriod($period);

        $requested = 0;
        $failures = [];

        Publisher::query()
            ->where('status', PublisherStatus::Approved)
            ->with(['user:id,email', 'paymentMethods'])
            ->chunkById(100, function ($publishers) use ($period, &$requested, &$failures): void {
                foreach ($publishers as $publisher) {
                    $outcome = $this->requestFor($publisher, $period);

                    if ($outcome === true) {
                        $requested++;

                        continue;
                    }

                    if (! is_string($outcome)) {
                        continue;
                    }

                    $failures[] = [
                        'pub_id' => $publisher->pub_id,
                        'reason' => $outcome,
                    ];

                    $this->mailPublisher($publisher, $outcome);
                }
            });

        if ($failures !== []) {
            $this->mailOps($failures);
        }

        return ['requested' => $requested, 'skipped' => count($failures), 'statements' => $statements];
    }

    /**
     * True when a request was opened, a reason string when the rules failed,
     * null when this cycle already has a request (idempotent rerun).
     */
    private function requestFor(Publisher $publisher, CarbonImmutable $period): true|string|null
    {
        if ($this->requestedThisMonth($publisher)) {
            return null;
        }

        $blocked = $this->withdrawals->blockedReason($publisher);

        if ($blocked !== null) {
            $this->statements->noteBlocked($publisher, $period, $blocked, $this->isCarryReason($publisher));

            return $blocked;
        }

        $method = $publisher->paymentMethods->first(
            fn (PaymentMethod $method): bool => $method->is_default,
        );

        if ($method === null) {
            $reason = 'Set a default payment method for the monthly payout.';
            $this->statements->noteBlocked($publisher, $period, $reason, false);

            return $reason;
        }

        // One publisher's bad row must not abort the run for the other four
        // hundred: the service's refusal becomes this publisher's skip reason.
        try {
            $withdrawal = $this->withdrawals->request($publisher, $publisher->withdrawableBalance(), $method);
        } catch (RuntimeException $e) {
            $this->statements->noteBlocked($publisher, $period, $e->getMessage(), false);

            return $e->getMessage();
        }

        $this->statements->settle($withdrawal);

        return true;
    }

    /**
     * Too small or negative is "carried forward" — the balance rolls into the
     * next run by itself. Anything else (no method, suspended) is a `pending`
     * statement waiting on the publisher or on us.
     */
    private function isCarryReason(Publisher $publisher): bool
    {
        $available = $publisher->withdrawableBalance();

        return bccomp($available, $this->withdrawals->minimumPayout($publisher), Money::SCALE) < 0;
    }

    private function requestedThisMonth(Publisher $publisher): bool
    {
        return $publisher->withdrawals()
            ->where('requested_at', '>=', now()->startOfMonth())
            ->exists();
    }

    private function mailPublisher(Publisher $publisher, string $reason): void
    {
        $email = $publisher->user?->email;

        if (! is_string($email) || $email === '') {
            return;
        }

        Mail::to($email)->queue(new MonthlyPayoutSkippedMail((string) $publisher->pub_id, $reason));
    }

    /**
     * @param  list<array{pub_id: int, reason: string}>  $failures
     */
    private function mailOps(array $failures): void
    {
        $recipients = AdminAlert::recipients();

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->queue(new MonthlyPayoutErrorsMail($failures));
    }
}
