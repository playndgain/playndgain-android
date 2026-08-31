<?php

namespace App\Console\Commands;

use App\Enums\ConversionStatus;
use App\Enums\LedgerEntryType;
use App\Models\Conversion;
use App\Services\Money\Money;
use App\Support\AdminAlert;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The step `ledger:reconcile` does not cover — docs/04 §1, docs/06 §6.
 *
 * `ledger:reconcile` proves each cached balance equals the sum of that
 * publisher's entries. It says nothing about whether the entries are the right
 * amount in the first place: a conversion credited at the wrong rev-share
 * reconciles perfectly against its own wrong entry. This walks the other half
 * of the chain — the publisher's payout is exactly their share of what the
 * network reported, and the ledger holds exactly that payout and nothing else.
 *
 * Reports and exits non-zero. It never corrects: an amount silently repaired
 * nightly is a bug that is never found, and money history is append-only
 * anyway — the fix for a bad entry is an `adjustment`, written by a human who
 * knows why.
 *
 * `currency_amount` is deliberately not audited. It is `payout × the app's
 * coin rate`, and the rate is a mutable column on `apps` that was never
 * snapshotted onto the conversion. Re-deriving it would flag every app that
 * has ever changed its rate — which is a rate change, not a mis-credit.
 */
class AuditConversionMoneyCommand extends Command
{
    protected $signature = 'money:audit';

    protected $description = 'Re-derive every conversion payout from its own revenue and rev-share, and check its ledger entries against it.';

    /** Keyed by `trans_id` — a row failing both checks is still one bad conversion.
     *
     * @var array<string, true>
     */
    private array $problems = [];

    public function handle(): int
    {
        // ponytail: full table scan, chunked. Nightly at 3am over a conversions
        // table that is never pruned — add a `--since` window if the sweep ever
        // outgrows the night.
        Conversion::query()
            ->withoutGlobalScopes()
            ->chunkById(500, function (Collection $conversions): void {
                $entries = $this->ledgerTotals($conversions->modelKeys());

                foreach ($conversions as $conversion) {
                    $this->checkPayout($conversion);
                    $this->checkLedger($conversion, $entries[$conversion->id] ?? []);
                }
            });

        if ($this->problems !== []) {
            $count = count($this->problems);

            $this->components->error("{$count} conversion(s) disagree with their own inputs. Not corrected — find the writer.");

            return self::FAILURE;
        }

        $this->components->info('Every conversion payout matches its revenue and rev-share, and its ledger entries match the payout.');

        return self::SUCCESS;
    }

    /** `payout = revenue × rev_share_applied%`, recomputed from the row's own snapshot of the share. */
    private function checkPayout(Conversion $conversion): void
    {
        $expected = Money::share($conversion->revenue, Money::format($conversion->rev_share_applied, 2));
        $actual = Money::format($conversion->payout);

        // bccomp at six places, not `==` on floats — the whole reason this
        // codebase keeps money in strings.
        if (bccomp($expected, $actual, Money::SCALE) === 0) {
            return;
        }

        $this->report($conversion, 'payout', [
            'revenue' => Money::format($conversion->revenue),
            'rev_share_applied' => Money::format($conversion->rev_share_applied, 2),
            'expected' => $expected,
            'actual' => $actual,
        ]);
    }

    /**
     * What the ledger is allowed to hold for a conversion, by status. The rules
     * are status-shaped rather than "a chargeback exists", because a reversal
     * only writes one when the conversion was approved at the time — and that
     * fact is not stored anywhere. Netting to zero is what both cases have in
     * common.
     *
     * @param  array<string, string>  $totals  entry type → summed amount
     */
    private function checkLedger(Conversion $conversion, array $totals): void
    {
        $earning = $totals[LedgerEntryType::Earning->value] ?? '0';
        $chargeback = $totals[LedgerEntryType::Chargeback->value] ?? '0';
        $net = bcadd($earning, $chargeback, Money::SCALE);
        $payout = Money::format($conversion->payout);

        // A test conversion proves an integration works. It never reaches a
        // balance, so any entry at all is one too many.
        $expected = match (true) {
            $conversion->status === ConversionStatus::Reversed => '0',
            $conversion->is_test => '0',
            $conversion->status === ConversionStatus::Approved => $payout,
            default => '0',
        };

        if (bccomp($net, $expected, Money::SCALE) === 0) {
            return;
        }

        $this->report($conversion, 'ledger', [
            'status' => $conversion->status->value,
            'is_test' => $conversion->is_test ? '1' : '0',
            'earning' => $earning,
            'chargeback' => $chargeback,
            'expected' => $expected,
            'actual' => $net,
        ]);
    }

    /**
     * One query per chunk, grouped in PHP. Reading the entries per conversion
     * would be the N+1 a nightly sweep of the whole table feels first.
     *
     * @param  list<int|string>  $ids
     * @return array<int, array<string, string>>
     */
    private function ledgerTotals(array $ids): array
    {
        $rows = DB::table('ledger_entries')
            ->where('reference_type', (new Conversion)->getMorphClass())
            ->whereIn('reference_id', $ids)
            ->whereIn('type', [LedgerEntryType::Earning->value, LedgerEntryType::Chargeback->value])
            ->groupBy('reference_id', 'type')
            // The sum has to stay a decimal string; a float aggregate is the
            // drift this command exists to detect.
            ->selectRaw('reference_id, type, CAST(COALESCE(SUM(amount), 0) AS CHAR) AS total')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->reference_id][(string) $row->type] = (string) $row->total;
        }

        return $totals;
    }

    /** @param  array<string, string>  $context */
    private function report(Conversion $conversion, string $check, array $context): void
    {
        $this->problems[$conversion->trans_id] = true;

        // Raised per row, not once per run: the log needs every conversion to
        // find the writer. AdminAlert logs all of them and mails the first.
        AdminAlert::raise("money.audit.{$check}", $conversion, [
            'conversion_id' => $conversion->id,
            'trans_id' => $conversion->trans_id,
            ...$context,
        ]);

        $this->components->error(sprintf(
            'Conversion %s (%s): expected %s, found %s.',
            $conversion->trans_id,
            $check,
            $context['expected'],
            $context['actual'],
        ));
    }
}
