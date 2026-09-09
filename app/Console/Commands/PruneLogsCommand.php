<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Log retention — docs/11-infrastructure.md §5.
 *
 * Four tables grow without bound and none of them is money. Clicks are the
 * worst of them and are already summed into `daily_stats` by `stats:aggregate`,
 * so a pruned click has not lost a number anyone reports on.
 *
 * `conversions`, `ledger_entries` and `daily_stats` are absent from the
 * retention table on purpose: they are the audit trail and they are kept
 * forever. A conversion whose click is pruned keeps its own snapshot of the
 * offer, the payout and the rev-share — `conversions.offer_click_id` is
 * `nullOnDelete`, so the row survives with its evidence intact and only the
 * join to the raw click goes.
 *
 * Deletes run in batches with a pause between them rather than as one
 * statement. A single `DELETE ... WHERE created_at < ?` over six months of
 * clicks holds row locks for as long as it takes to finish, and the wall is
 * writing to that table the whole time.
 */
class PruneLogsCommand extends Command
{
    protected $signature = 'logs:prune
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete log rows past their retention window (docs/11 §5)';

    public function handle(): int
    {
        /** @var array<string, int> $retention */
        $retention = config('playndgain.log_retention');
        $batch = (int) config('playndgain.log_prune_batch', 10000);
        $pause = (int) config('playndgain.log_prune_pause_ms', 200);
        $dryRun = (bool) $this->option('dry-run');

        $total = 0;

        foreach ($retention as $table => $days) {
            $cutoff = CarbonImmutable::now()->subDays($days);
            $deleted = $dryRun
                ? $this->countBefore($table, $cutoff)
                : $this->pruneBefore($table, $cutoff, $batch, $pause);

            $total += $deleted;

            $verb = $dryRun ? 'would delete' : 'deleted';
            $this->components->info("{$table}: {$verb} {$deleted} row(s) older than {$days} days.");
        }

        $this->components->info(($dryRun ? 'Dry run: ' : '').number_format($total).' row(s) total.');

        return self::SUCCESS;
    }

    private function countBefore(string $table, CarbonImmutable $cutoff): int
    {
        return DB::table($table)->where('created_at', '<', $cutoff)->count();
    }

    /**
     * Delete in batches, pausing between them so the table stays writable.
     *
     * `limit()` on a delete needs an ordering the index can serve; `created_at`
     * is indexed on every table listed in the retention config, and it is also
     * the column being filtered, so the batch walks the index it is already in.
     */
    private function pruneBefore(string $table, CarbonImmutable $cutoff, int $batch, int $pauseMs): int
    {
        $deleted = 0;

        do {
            $rows = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->orderBy('created_at')
                ->limit($batch)
                ->delete();

            $deleted += $rows;

            if ($rows > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        } while ($rows > 0);

        return $deleted;
    }
}
