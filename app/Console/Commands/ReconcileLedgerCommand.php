<?php

namespace App\Console\Commands;

use App\Models\Publisher;
use App\Services\Money\Money;
use App\Support\AdminAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly drift check — docs/06-ledger-payouts.md §6.
 *
 * Drift means money code wrote a cached balance without the entry that
 * justifies it, or the entry without the balance. Either way it is a bug, and
 * silently correcting it nightly means never finding the cause.
 */
class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile';

    protected $description = 'Re-derive every publisher balance from ledger_entries and report drift.';

    public function handle(): int
    {
        $drifted = 0;

        Publisher::query()->withTrashed()->chunkById(200, function ($publishers) use (&$drifted): void {
            foreach ($publishers as $publisher) {
                $derived = $this->derive($publisher->id);
                $cached = Money::format($publisher->balance);

                // bccomp at six places, not `==` on floats — comparing money
                // with `==` is how drift alerts start firing on rows that are fine.
                if (bccomp($derived, $cached, Money::SCALE) === 0) {
                    continue;
                }

                $drifted++;

                // Raised per drifted publisher, not once per run: the log needs
                // every row to find the writer. AdminAlert writes all of them
                // and mails the first, which is the split that makes raising it
                // in a loop safe.
                AdminAlert::raise('ledger.drift', $publisher, [
                    'publisher_id' => $publisher->id,
                    'cached' => $cached,
                    'derived' => $derived,
                ]);

                $this->components->error(
                    "Publisher #{$publisher->pub_id}: cached {$cached}, ledger {$derived}."
                );
            }
        });

        if ($drifted > 0) {
            $this->components->error("{$drifted} publisher balance(s) disagree with the ledger. Not corrected — find the writer.");

            return self::FAILURE;
        }

        $this->components->info('Every publisher balance matches the ledger.');

        return self::SUCCESS;
    }

    /** Raw query: the sum must stay a decimal string, and float aggregates do not. */
    private function derive(int $publisherId): string
    {
        return (string) DB::table('ledger_entries')
            ->where('publisher_id', $publisherId)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');
    }
}
