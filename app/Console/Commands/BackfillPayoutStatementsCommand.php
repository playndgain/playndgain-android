<?php

namespace App\Console\Commands;

use App\Enums\WithdrawalStatus;
use App\Models\LedgerEntry;
use App\Models\Publisher;
use App\Models\Withdrawal;
use App\Services\Money\PayoutStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time step after the statements migration — docs/11 §4. Writes a
 * statement for every complete month a publisher has ledger activity in, then
 * links the withdrawals that already paid them. Safe to rerun: existing
 * statements are never touched.
 */
class BackfillPayoutStatementsCommand extends Command
{
    protected $signature = 'payouts:backfill-statements
        {--dry-run : Compute and print without writing}
        {--publisher= : Only this publisher (pub_id)}
        {--link-through= : Let pre-system withdrawals settle statements up to this month (Y-m), regardless of request date}';

    protected $description = 'Generate monthly payout statements for historical ledger activity and link paid withdrawals.';

    public function handle(PayoutStatementService $statements): int
    {
        $dry = (bool) $this->option('dry-run');
        $lastPeriod = CarbonImmutable::now()->startOfMonth()->subMonth();
        $linkThrough = $this->option('link-through') === null
            ? null
            : CarbonImmutable::createFromFormat('Y-m-d', $this->option('link-through').'-01')->startOfMonth();

        $generated = 0;
        $settled = 0;

        $publishers = Publisher::query()->orderBy('id');

        if (($pubId = $this->option('publisher')) !== null) {
            $publishers->where('pub_id', (int) $pubId);
        }

        $publishers->chunkById(100, function ($publishers) use ($statements, $dry, $lastPeriod, $linkThrough, &$generated, &$settled): void {
            foreach ($publishers as $publisher) {
                $first = LedgerEntry::query()
                    ->withoutGlobalScopes()
                    ->where('publisher_id', $publisher->id)
                    ->min('created_at');

                if ($first === null) {
                    continue;
                }

                $months = [];

                for ($period = CarbonImmutable::parse($first)->startOfMonth(); $period->lte($lastPeriod); $period = $period->addMonth()) {
                    $sums = $statements->sums($publisher->id, $period);

                    $active = bccomp($sums['gross'], '0', 6) !== 0
                        || bccomp($sums['chargebacks'], '0', 6) !== 0
                        || bccomp($sums['adjustments'], '0', 6) !== 0;

                    if (! $active) {
                        continue;
                    }

                    $months[] = $period->format('Y-m');

                    if ($dry) {
                        $generated++;

                        continue;
                    }

                    $statement = $statements->generate($publisher, $period);

                    if ($statement->wasRecentlyCreated) {
                        $generated++;
                    }
                }

                $withdrawals = Withdrawal::query()
                    ->withoutGlobalScopes()
                    ->where('publisher_id', $publisher->id)
                    ->whereIn('status', [WithdrawalStatus::Requested, WithdrawalStatus::Processing, WithdrawalStatus::Paid])
                    ->orderBy('requested_at')
                    ->get();

                if (! $dry) {
                    // Natural reach first, oldest withdrawal first: a payout
                    // settles the months that had cleared when it was asked for.
                    foreach ($withdrawals as $withdrawal) {
                        $settled += $this->link($statements, $withdrawal);
                    }

                    // Then the pre-system ones. A request made inside the
                    // month it was paying for (before payouts were monthly)
                    // could not reach that month by the rule above; with
                    // --link-through it may. Latest first, so where several
                    // such requests sit in one month the last one settles it
                    // and the earlier ones show up as the carried line.
                    if ($linkThrough !== null) {
                        foreach ($withdrawals->reverse() as $withdrawal) {
                            $settled += $this->link($statements, $withdrawal, $linkThrough);
                        }
                    }
                }

                if ($months !== []) {
                    $this->line(sprintf(
                        '#%d %s: %s — %d withdrawal(s)',
                        $publisher->pub_id,
                        $publisher->company_name ?? '',
                        implode(', ', $months),
                        $withdrawals->count(),
                    ));
                }
            }
        });

        if ($dry && $linkThrough !== null) {
            $this->line('[dry run] --link-through is applied on the real run only; linking is not simulated.');
        }

        $this->info(sprintf(
            '%s%d statement(s) generated, %d linked to withdrawals.',
            $dry ? '[dry run] ' : '',
            $generated,
            $settled,
        ));

        return self::SUCCESS;
    }

    private function link(PayoutStatementService $statements, Withdrawal $withdrawal, ?CarbonImmutable $through = null): int
    {
        return DB::transaction(function () use ($statements, $withdrawal, $through): int {
            $count = $statements->settle($withdrawal, $through);

            if ($count > 0 && $withdrawal->status === WithdrawalStatus::Paid) {
                $statements->markPaid($withdrawal);
            }

            return $count;
        });
    }
}
