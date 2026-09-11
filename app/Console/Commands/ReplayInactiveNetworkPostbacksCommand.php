<?php

namespace App\Console\Commands;

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Http\Controllers\PostbackController;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\Network;
use App\Networks\DTO\PostbackDTO;
use App\Networks\NetworkDriverManager;
use App\Services\Money\ConversionProcessor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Repair for the chargebacks the not-active gate ate — docs/04 §1.
 *
 * `PostbackController` used to refuse every postback to a disabled network
 * before it knew what the postback was, so a network switched off after it had
 * already earned — a failed sync, a rotated key — had its chargebacks answered
 * 403 while its credits stayed on the books. Networks retry a handful of times
 * and stop; the gate is fixed, but nothing is coming back to re-deliver them.
 *
 * This replays those logged rows through the same driver that would have
 * parsed them live and hands the reversals to the same processor, so a replayed
 * chargeback is indistinguishable from one that landed on time: the conversion
 * flips to `reversed`, the publisher is debited by a `chargeback` ledger entry,
 * and their callback URL gets the reversal postback.
 *
 * Credits are deliberately left alone. A disabled network still must not earn,
 * and re-crediting one that has been off for days would pay for conversions
 * nobody has verified. Reversals only ever take money off a conversion already
 * recorded under the same network, which is why they are safe to apply late.
 *
 * Idempotent — a conversion already `reversed` logs as a duplicate and moves
 * money once, which is also what the eleven retries of each chargeback do.
 */
class ReplayInactiveNetworkPostbacksCommand extends Command
{
    protected $signature = 'postbacks:replay-chargebacks
        {--driver= : Limit to one network driver key}
        {--no-callbacks : Move the money but do not queue the publisher reversal callbacks}
        {--dry-run : Report what would move without touching anything}';

    protected $description = 'Re-apply chargebacks that were refused because their network was not active.';

    /** @var array<string, int> */
    private array $tally = [
        'reversed' => 0,
        'rejected' => 0,
        'already settled' => 0,
        'no conversion' => 0,
        'not a reversal' => 0,
        'unparseable' => 0,
        'no driver' => 0,
    ];

    /** Dry-run only: `network|txid` => the outcome its first row forecast.
     *
     * @var array<string, string>
     */
    private array $seen = [];

    public function handle(NetworkDriverManager $drivers, ConversionProcessor $processor): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('no-callbacks')) {
            // Days-old chargebacks arriving at a publisher's endpoint all at
            // once is a conversation to have before it happens, so the ledger
            // can be repaired now and the callbacks sent by hand after. The
            // `null` driver discards at the connection, which catches the
            // dispatch inside `reverse()` without threading a flag down
            // through the processor. Nothing else this command runs queues
            // anything, so nothing else is lost.
            config(['queue.default' => 'null']);

            $this->comment('Callbacks suppressed — re-send them per conversion from the admin panel.');
        }

        IncomingPostback::query()
            ->where('result', IncomingPostbackResult::Rejected)
            ->where('reject_reason', PostbackController::INACTIVE_REASON)
            ->when($this->option('driver'), fn ($query, string $driver) => $query->where('driver', $driver))
            ->with('network')
            // Oldest first: a chargeback and its ten retries have to arrive in
            // the order they did, or the retry reverses and the original is the
            // one recorded as the duplicate.
            ->chunkById(200, function (Collection $logs) use ($drivers, $processor, $dryRun): void {
                foreach ($logs as $log) {
                    $this->replay($log, $drivers, $processor, $dryRun);
                }
            });

        foreach ($this->tally as $outcome => $count) {
            if ($count > 0) {
                $this->line(sprintf('%-16s %d', $outcome, $count));
            }
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    private function replay(
        IncomingPostback $log,
        NetworkDriverManager $drivers,
        ConversionProcessor $processor,
        bool $dryRun,
    ): void {
        $network = $log->network;

        if (! $network instanceof Network || ! $drivers->has($network->driver)) {
            $this->tally['no driver']++;

            return;
        }

        // The stored payload re-parsed by the driver, not the columns the admin
        // table reads out of it — a replayed row has to go through the same
        // code the live request would have.
        $payload = $log->query ?: $log->body ?: [];
        $dto = $drivers->make($network)->parsePostback(Request::create('/postback/'.$network->driver, 'GET', $payload));

        if ($dto === null) {
            $this->tally['unparseable']++;

            return;
        }

        if (! $dto->isReversal()) {
            $this->tally['not a reversal']++;

            return;
        }

        if ($dryRun) {
            $this->forecast($network, $dto);

            return;
        }

        $conversion = $processor->reverse($dto, $network, $log);

        // A reversal that lands on a row nobody was credited for is a rejection
        // now, not a reversal (docs/04 §3, D2). The two move the same money —
        // none — but reporting eleven "reversed" for eleven declines nobody was
        // ever paid for overstates what this command did.
        $outcome = match (true) {
            $log->result === IncomingPostbackResult::Duplicate => 'already settled',
            $log->result !== IncomingPostbackResult::Accepted => 'no conversion',
            $conversion?->status === ConversionStatus::Rejected => 'rejected',
            default => 'reversed',
        };

        // `reverse()` writes the result but has no reason to know the row is
        // being replayed, so an accepted row would keep reading "network is not
        // active" as its reason forever. A row it could not match is the one
        // case to leave alone — it has just written the truer reason itself.
        if ($outcome !== 'no conversion') {
            $log->update(['reject_reason' => null]);
        }

        $this->tally[$outcome]++;
    }

    /**
     * What the live run would report for this row, without writing anything.
     *
     * The live run learns "already reversed" from the conversion in front of
     * it. A dry run has to work the same thing out from the rows it has already
     * walked, or it reports each chargeback's eleven retries as eleven
     * chargebacks and overstates what moves by an order of magnitude.
     */
    private function forecast(Network $network, PostbackDTO $dto): void
    {
        $key = $network->id.'|'.$dto->networkTxId;

        if (isset($this->seen[$key])) {
            $this->tally[$this->seen[$key] === 'no conversion' ? 'no conversion' : 'already settled']++;

            return;
        }

        $status = Conversion::query()
            ->withoutGlobalScopes()
            ->where('network_id', $network->id)
            ->where('network_txid', $dto->networkTxId)
            ->value('status');

        $this->seen[$key] = match ($status) {
            null => 'no conversion',
            // Already settled one way or the other: a reversed row is a
            // duplicate, and a rejected one stays rejected.
            ConversionStatus::Reversed, ConversionStatus::Rejected => 'already settled',
            // Nobody was credited, so the decline lands as a rejection.
            ConversionStatus::Pending => 'rejected',
            default => 'reversed',
        };

        $this->tally[$this->seen[$key]]++;
    }
}
