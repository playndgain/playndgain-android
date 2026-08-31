<?php

namespace App\Services\Money;

use App\Enums\PublisherStatus;
use App\Mail\MonthlyPayoutErrorsMail;
use App\Mail\MonthlyPayoutSkippedMail;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use App\Support\AdminAlert;
use Illuminate\Support\Facades\Mail;

/**
 * End-of-month withdrawal requests — one per approved publisher, on their
 * default payment method, for the full withdrawable balance.
 *
 * Publishers who fail the payout rules are not requested. They get a skip
 * mail; ops get one error mail listing every failure for the run.
 */
final class MonthlyWithdrawalService
{
    public function __construct(private WithdrawalService $withdrawals) {}

    /**
     * @return array{requested: int, skipped: int}
     */
    public function requestDue(): array
    {
        $requested = 0;
        $failures = [];

        Publisher::query()
            ->where('status', PublisherStatus::Approved)
            ->with(['user:id,email', 'paymentMethods'])
            ->chunkById(100, function ($publishers) use (&$requested, &$failures): void {
                foreach ($publishers as $publisher) {
                    $outcome = $this->requestFor($publisher);

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

        return ['requested' => $requested, 'skipped' => count($failures)];
    }

    /**
     * True when a request was opened, a reason string when the rules failed,
     * null when this cycle already has a request (idempotent rerun).
     */
    private function requestFor(Publisher $publisher): true|string|null
    {
        if ($this->requestedThisMonth($publisher)) {
            return null;
        }

        $blocked = $this->withdrawals->blockedReason($publisher);

        if ($blocked !== null) {
            return $blocked;
        }

        $method = $publisher->paymentMethods->first(
            fn (PaymentMethod $method): bool => $method->is_default,
        );

        if ($method === null) {
            return 'Set a default payment method for the monthly payout.';
        }

        $this->withdrawals->request($publisher, $publisher->withdrawableBalance(), $method);

        return true;
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
