<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatementStatus;
use App\Enums\WithdrawalStatus;
use App\Models\PayoutStatement;
use App\Models\Publisher;
use App\Services\Money\Money;
use App\Services\Money\PayoutStatementService;
use App\Support\AdminAlert;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Structural check on monthly statements — docs/06 §9.
 *
 * A statement's net is allowed to differ from the amount paid (that is the
 * carry); what is not allowed is a statement that says `paid` against a
 * payout that was not, a statement hanging off a rejected payout, or a row
 * whose own arithmetic or due date is wrong. Like `ledger:reconcile`, this
 * reports and never corrects.
 */
class ReconcilePayoutStatementsCommand extends Command
{
    protected $signature = 'payouts:reconcile-statements {--publisher= : Only this publisher (pub_id)}';

    protected $description = 'Check every payout statement against its withdrawal and its own arithmetic; report drift.';

    public function handle(): int
    {
        $failures = 0;

        $publishers = Publisher::query()->withTrashed();

        if (($pubId = $this->option('publisher')) !== null) {
            $publishers->where('pub_id', (int) $pubId);
        }

        $publishers->chunkById(200, function ($publishers) use (&$failures): void {
            foreach ($publishers as $publisher) {
                $problems = $this->problemsFor($publisher);

                if ($problems === []) {
                    continue;
                }

                $failures += count($problems);

                AdminAlert::raise('statements.drift', $publisher, [
                    'publisher_id' => $publisher->id,
                    'problems' => implode(' | ', $problems),
                ]);

                foreach ($problems as $problem) {
                    $this->components->error("Publisher #{$publisher->pub_id}: {$problem}");
                }
            }
        });

        if ($failures > 0) {
            $this->components->error("{$failures} statement problem(s). Not corrected — inspect the ledger and the withdrawal first.");

            return self::FAILURE;
        }

        $this->components->info('Every payout statement agrees with its withdrawal.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function problemsFor(Publisher $publisher): array
    {
        $problems = [];

        $statements = PayoutStatement::query()
            ->withoutGlobalScopes()
            ->with('withdrawal')
            ->where('publisher_id', $publisher->id)
            ->orderBy('period')
            ->get();

        foreach ($statements as $statement) {
            $label = $statement->period->format('Y-m');

            $net = bcadd(bcadd(Money::format($statement->gross), Money::format($statement->chargebacks), Money::SCALE), Money::format($statement->adjustments), Money::SCALE);

            if (bccomp($net, Money::format($statement->net), Money::SCALE) !== 0) {
                $problems[] = "{$label}: net {$statement->net} is not gross + chargebacks + adjustments ({$net}).";
            }

            $due = PayoutStatementService::dueDate(CarbonImmutable::instance($statement->period))->toDateString();

            if ($statement->due_date->toDateString() !== $due) {
                $problems[] = "{$label}: due date {$statement->due_date->toDateString()} should be {$due}.";
            }

            $withdrawal = $statement->withdrawal;

            if ($statement->status === PayoutStatementStatus::Paid) {
                if ($withdrawal === null) {
                    $problems[] = "{$label}: paid with no withdrawal.";
                } elseif ($withdrawal->status !== WithdrawalStatus::Paid) {
                    $problems[] = "{$label}: paid against withdrawal #{$withdrawal->id} which is {$withdrawal->status->value}.";
                }

                if ($statement->paid_at === null) {
                    $problems[] = "{$label}: paid without a paid_at.";
                }

                continue;
            }

            // pending / carried
            if ($statement->paid_at !== null) {
                $problems[] = "{$label}: {$statement->status->value} but carries a paid_at.";
            }

            if ($withdrawal === null) {
                continue;
            }

            if ($statement->status === PayoutStatementStatus::Carried) {
                $problems[] = "{$label}: carried but linked to withdrawal #{$withdrawal->id}.";
            }

            if ($withdrawal->status === WithdrawalStatus::Rejected) {
                $problems[] = "{$label}: linked to rejected withdrawal #{$withdrawal->id}.";
            } elseif ($withdrawal->status === WithdrawalStatus::Paid) {
                $problems[] = "{$label}: still {$statement->status->value} although withdrawal #{$withdrawal->id} is paid.";
            }

            // The live rule is "cleared before the request"; the backfill's
            // --link-through deliberately lets a request made inside the
            // month settle it. What is never right is a request made before
            // the month even began.
            if ($statement->period->gt(CarbonImmutable::instance($withdrawal->requested_at ?? now())->startOfMonth())) {
                $problems[] = "{$label}: linked to withdrawal #{$withdrawal->id} requested before the month began.";
            }
        }

        return $problems;
    }
}
