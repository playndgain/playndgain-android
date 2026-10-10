<?php

namespace App\Services\Money;

use App\Enums\ConversionStatus;
use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\IncomingPostbackResult;
use App\Enums\OutgoingPostbackKind;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\FraudContext;
use App\Fraud\FraudPipeline;
use App\Jobs\DispatchPublisherPostback;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Conversion;
use App\Models\FraudRule;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\Network;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Models\Publisher;
use App\Models\User;
use App\Networks\DTO\PostbackDTO;
use App\Support\AdminAlert;
use App\Support\TransactionId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Steps 6 of the inbound pipeline — docs/04-conversions-postbacks.md §1, §3.
 *
 * Everything that moves money passes through here, and nothing here trusts a
 * pre-check: the `(network_id, network_txid)` unique index is what settles a
 * duplicate, because two workers racing the same postback is normal traffic.
 */
class ConversionProcessor
{
    /**
     * `conversions.rejected_reason` when the *network* refused it, as opposed
     * to a fraud rule key or an admin's own words.
     *
     * The distinction decides whether a later upstream approval can revive the
     * row: a rejection we only made because the network said so is theirs to
     * take back, and one our own rules made is not (D3).
     */
    public const NETWORK_REJECTED = 'network_rejected';

    /** `offer_name` for a partner-wall conversion whose postback named nothing. */
    public const PARTNER_OFFER_NAME = 'Partner wall offer';

    /**
     * The rule key that rejected the conversion being recorded, set by
     * `evaluateFraudRules()` on the way past. docs/01 §7 puts the rule key in
     * `conversions.rejected_reason`, and the write is three frames below the
     * only method that knows it. Reset on every evaluation.
     */
    private ?string $fraudReason = null;

    /**
     * `credited`, `pending` and `rejected` are the same write with a different
     * status — only `approved` moves money or notifies the publisher.
     */
    public function record(PostbackDTO $dto, Network $network, IncomingPostback $log): ?Conversion
    {
        // Cleared per postback, not per fraud evaluation. A network-sent
        // `rejected` never reaches evaluateFraudRules(), so without this a
        // fraud-blocked conversion earlier in the same instance would lend its
        // rule key to the next one and label a network rejection as fraud.
        $this->fraudReason = null;

        $click = $this->resolveClick($dto, $network);

        if ($click === null) {
            // A network test fire, a truncated subid, an offer clicked before a
            // migration. The row stays in the log for the admin to attach by hand;
            // discarding it is how you tell a publisher "we never got it".
            $log->update([
                'result' => IncomingPostbackResult::UnknownClick,
                'reject_reason' => 'no click matches '.($dto->clickId ?? 'a missing click_id'),
            ]);

            return null;
        }

        // A txid we already hold is not a new conversion, whatever the network
        // is calling it this time. Asking before the create keeps the whole
        // create path — the fraud pipeline, and the `IpIntelligence` lookup
        // inside it — off a retry that was only ever going to collide, and lets
        // `reconcile()` run the one fraud evaluation that has the existing row
        // in its context. The create below is still the concurrency control:
        // a row that appears between this read and that insert lands in the
        // unique-violation branch and reconciles from there.
        $existing = $this->existing($dto, $network);

        if ($existing !== null) {
            return $this->reconcile($dto, $network, $log, $existing);
        }

        // Resolved by key rather than through the click's relations: a trashed
        // app or publisher has to come back null here, not earn.
        $app = App::query()->find($click->app_id);
        $publisher = Publisher::query()->find($click->publisher_id);

        if ($app === null || $publisher === null) {
            $log->update([
                'result' => IncomingPostbackResult::Error,
                'reject_reason' => 'app or publisher no longer exists',
            ]);

            return null;
        }

        $offer = Offer::query()->find($click->offer_id);
        $revenue = Money::format($dto->payout);
        $revShare = Money::format($publisher->rev_share, 2);
        $payout = Money::share($revenue, $revShare);
        $status = $this->resolveStatus($dto, $click);
        $now = now();

        $collided = false;

        $conversion = DB::transaction(function () use ($dto, $network, $log, $click, $app, $publisher, $offer, $revenue, $revShare, $payout, $status, $now, &$collided): ?Conversion {
            try {
                $conversion = Conversion::create([
                    'trans_id' => TransactionId::generate($dto->offerExternalId),
                    'network_txid' => $dto->networkTxId,
                    'click_id' => $click->click_id,
                    'offer_click_id' => $click->id,
                    'app_id' => $app->id,
                    'publisher_id' => $publisher->id,
                    'offer_id' => $click->offer_id,
                    'network_id' => $network->id,
                    'end_user_id' => $dto->endUserId ?? $click->end_user_id,
                    // An embedded partner wall has no offer of ours, and the column is NOT
                    // NULL. Never the network's name: this column reaches the
                    // publisher panel, the {offer_name} postback macro and the
                    // end user's history (AGENTS.md, network anonymity). The
                    // admin still sees the network through `network_id`.
                    'offer_name' => $dto->offerName ?? $offer->name ?? self::PARTNER_OFFER_NAME,
                    'revenue' => $revenue,
                    'payout' => $payout,
                    'rev_share_applied' => $revShare,
                    'currency_amount' => Money::currency($payout, $app->currency_rate),
                    'status' => $status,
                    'is_test' => $click->is_test,
                    'rejected_reason' => $status === ConversionStatus::Rejected
                        ? ($this->fraudReason ?? self::NETWORK_REJECTED)
                        : null,
                    'ip' => $dto->ip ?? $click->ip,
                    'country' => $click->country,
                    // Calendar month, not a rolling window. January earnings
                    // clear 1 Feb 00:00; a conversion on the 1st waits until
                    // the following month. Chargebacks ignore this and debit now.
                    'clears_at' => $now->copy()->startOfMonth()->addMonth(),
                    'created_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another worker won the race between the read above and this
                // insert. Close the transaction before reconciling: the
                // reconciler evaluates fraud, which does HTTP, and it takes a
                // row lock of its own — neither belongs inside a transaction
                // opened to write a row that does not exist.
                $collided = true;

                return null;
            }

            // Test-mode conversions prove an integration works. They never reach
            // a balance, a chart, or an earnings figure.
            if ($status === ConversionStatus::Approved && ! $conversion->is_test) {
                LedgerEntry::createEarning($conversion);
            }

            $click->forceFill(['converted_at' => $now])->save();

            $log->update([
                'result' => IncomingPostbackResult::Accepted,
                'conversion_id' => $conversion->id,
            ]);

            return $conversion;
        });

        if ($collided) {
            return $this->reconcile($dto, $network, $log);
        }

        if ($conversion?->status === ConversionStatus::Approved) {
            $this->notifyPublisher($conversion, OutgoingPostbackKind::Credit);
        }

        return $conversion;
    }

    /**
     * A partner reverses when the advertiser rejects — fraud, a refund, a
     * duplicate install.
     *
     * The decision itself lives in `reconcile()`, because a reversal is one
     * column of the same matrix: what it means depends entirely on what the row
     * is already. Only the "no row at all" case is settled here, and it keeps
     * its existing behaviour — logged as an error, HTTP 200, visible to an
     * admin, and no tombstone row invented from a postback we cannot attach to
     * anything (D6).
     */
    public function reverse(PostbackDTO $dto, Network $network, IncomingPostback $log): ?Conversion
    {
        $conversion = $this->existing($dto, $network);

        if ($conversion === null) {
            $log->update([
                'result' => IncomingPostbackResult::Error,
                'reject_reason' => 'no conversion for network_txid '.$dto->networkTxId,
            ]);

            return null;
        }

        return $this->reconcile($dto, $network, $log, $conversion);
    }

    /**
     * A second (or fifth) postback about a transaction we already hold.
     *
     * Upstream statuses arrive as a sequence, not a verdict: `pending` then
     * `approved`, or `approved` then `rejected` a week later when the
     * advertiser audits. Before this existed the unique index swallowed every
     * one of them as a duplicate, so a conversion that went pending first stayed
     * pending forever and only an admin could release it.
     *
     * Three properties hold, and each one is why a step is where it is:
     *
     *   1. **Fraud runs before the lock.** It performs an `IpIntelligence`
     *      lookup over HTTP; holding a row lock across that serialises every
     *      postback for the transaction behind a third party's latency.
     *   2. **The cell is chosen from the locked status**, never from the read
     *      that preceded it. Two workers reconciling the same txid both see
     *      `pending` outside the lock; only the re-read inside it tells the
     *      second one that the first already paid.
     *   3. **Ledger writes and publisher postbacks happen once**, inside the
     *      lock and after commit respectively.
     *
     * The full matrix is in docs/04 §3.
     */
    private function reconcile(
        PostbackDTO $dto,
        Network $network,
        IncomingPostback $log,
        ?Conversion $existing = null,
    ): ?Conversion {
        // A locking read, because the only way to reach this without a row in
        // hand is the unique index firing: the winning insert was committed by
        // another worker *after* this transaction's snapshot began, so a plain
        // `SELECT` still sees nothing while the index already sees everything.
        // `FOR UPDATE` reads the latest committed row rather than the snapshot,
        // which is the difference between reconciling the duplicate and
        // reporting a conversion that demonstrably exists as missing.
        $existing ??= $this->existing($dto, $network, forUpdate: true);

        if ($existing === null) {
            // Not a race, then: a deleted conversion, or a replica reading
            // behind. That is an invariant failure, and calling it a duplicate
            // would file it under normal traffic.
            Log::error('Reconcile found no conversion for a transaction that collided', [
                'network_id' => $network->id,
                'network_txid' => $dto->networkTxId,
            ]);

            $log->update([
                'result' => IncomingPostbackResult::Error,
                'reject_reason' => 'no conversion for network_txid '.$dto->networkTxId,
            ]);

            return null;
        }

        // The same gate `record()` applies before it creates a row, applied
        // again here because reconcile is reached without passing through it —
        // `reverse()` comes straight in, and the create path's own check sits
        // after the branch above. `chargeBack()` resolves the publisher with
        // `findOrFail`, and `Publisher` soft-deletes, so a trashed one would
        // otherwise raise inside the transaction and answer a partner's
        // reversal with a 500 instead of a logged refusal.
        if (App::query()->find($existing->app_id) === null || Publisher::query()->find($existing->publisher_id) === null) {
            $log->update([
                'result' => IncomingPostbackResult::Error,
                'reject_reason' => 'app or publisher no longer exists',
                'conversion_id' => $existing->id,
            ]);

            return null;
        }

        $incoming = $this->upstreamStatus($dto);

        // The conversion's own click, never the one this postback happened to
        // resolve. The two are normally the same row; where they differ — a
        // hosted wall whose fallback matched a live embed row after the
        // original click was pruned — pairing the original conversion with a
        // different click's timestamp makes `impossible_speed` measure an
        // interval that never happened. `conversions.offer_click_id` is
        // `nullOnDelete` and `logs:prune` deletes clicks, so null is a state
        // this reaches rather than a defensive impossibility.
        $click = OfferClick::query()->withoutGlobalScopes()->find($existing->offer_click_id);

        // Step 1: the fraud verdict, outside every lock. Only the rows that
        // could still become money are worth evaluating — a row that is already
        // approved, reversed, or rejected by our own rules is not going to be
        // paid by this postback whatever the pipeline says.
        $fraudStatus = null;
        $fraudReason = null;

        if ($incoming === ConversionStatus::Approved && $this->isApprovalCandidate($existing)) {
            if ($click === null) {
                // A row that could still become money, a network saying it
                // should, and no click to judge it against. There is no cell
                // for that: approving unevaluated would pay on no evidence and
                // filing it as a duplicate would hide a conversion that can
                // never be paid behind a result an admin scrolls past.
                $log->update([
                    'result' => IncomingPostbackResult::Error,
                    'reject_reason' => 'no click to evaluate for network_txid '.$dto->networkTxId,
                    'conversion_id' => $existing->id,
                ]);

                return $existing;
            }

            // The existing row goes into the context: `CountsConversions`
            // excludes it from its own cap, and `ImpossibleSpeedRule` measures
            // from the original conversion rather than from this retry, which
            // would otherwise read as a conversion arriving days after its click.
            $fraudStatus = $this->evaluateFraudRules($dto, $click, $existing);
            $fraudReason = $this->fraudReason;
        }

        $outcome = $this->outcomeFor($existing, $incoming, $fraudStatus, $network);

        // Step 2: a cell that writes nothing needs no lock. The upstream audit
        // fields are not money and nothing reads them to decide anything.
        if ($outcome === ReconcileOutcome::Duplicate) {
            $this->touchUpstream($existing, $incoming);

            $log->update([
                'result' => IncomingPostbackResult::Duplicate,
                'conversion_id' => $existing->id,
            ]);

            return $existing;
        }

        // Step 3: everything else, under the row lock, re-chosen from the
        // status the lock actually found.
        //
        // The locked model is carried back out rather than re-read afterwards:
        // a worker reconciling inside its own open transaction cannot see a row
        // another worker committed after its snapshot began, so a plain re-read
        // here would fail on exactly the race this method exists to survive.
        $locked = null;

        $notify = DB::transaction(function () use ($existing, $incoming, $fraudStatus, $fraudReason, $network, $dto, $log, &$locked): ?OutgoingPostbackKind {
            $locked = Conversion::query()
                ->withoutGlobalScopes()
                ->whereKey($existing->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $cell = $this->outcomeFor($locked, $incoming, $fraudStatus, $network);

            return $this->apply($cell, $locked, $incoming, $fraudReason, $dto, $log);
        });

        $conversion = $locked ?? $existing;

        // Step 4: publisher callbacks after the commit, so a rolled-back
        // transaction cannot leave a queued job describing money that never
        // moved.
        if ($notify !== null) {
            $this->notifyPublisher($conversion, $notify);
        }

        return $conversion;
    }

    /**
     * Pick the cell. Pure: no writes, no queries, so the documented table can
     * be asserted against it directly.
     */
    private function outcomeFor(
        Conversion $conversion,
        ConversionStatus $incoming,
        ?ConversionStatus $fraudStatus,
        Network $network,
    ): ReconcileOutcome {
        return match ($conversion->status) {
            ConversionStatus::Pending => match ($incoming) {
                ConversionStatus::Approved => $this->fraudOutcome($fraudStatus),
                // D2. A decline that arrives before we ever credited anyone is a
                // rejection, not a reversal: the money outcome is identical (no
                // chargeback, no postback) and `reversed` on a row nobody was
                // paid for reads as a chargeback that never happened.
                ConversionStatus::Rejected, ConversionStatus::Reversed => ReconcileOutcome::RejectNetwork,
                default => ReconcileOutcome::Duplicate,
            },
            ConversionStatus::Approved => match ($incoming) {
                ConversionStatus::Reversed => ReconcileOutcome::Reverse,
                // D7. Whether a late `rejected` means money back is a fact about
                // the connector, not about the word. MyChips and Offery send it
                // routinely and have never meant a chargeback by it.
                ConversionStatus::Rejected => $network->connector()?->rejectedAfterCreditReverses === true
                    ? ReconcileOutcome::Reverse
                    : ReconcileOutcome::AlertRejectedAfterCredit,
                default => ReconcileOutcome::Duplicate,
            },
            ConversionStatus::Rejected => match (true) {
                $incoming !== ConversionStatus::Approved => ReconcileOutcome::Duplicate,
                // D3. The network un-rejecting something we only ever rejected
                // *because* it said so is new information, and we never made a
                // decision of our own about it. It goes back through fraud.
                $conversion->rejected_reason === self::NETWORK_REJECTED => $this->fraudOutcome($fraudStatus),
                // A fraud rule or an admin decided this. An upstream approval
                // does not overturn either.
                default => ReconcileOutcome::RefuseInternalRejection,
            },
            ConversionStatus::Reversed => ReconcileOutcome::Duplicate,
        };
    }

    /**
     * A row whose status is still open to becoming money, and therefore worth
     * spending a fraud evaluation on.
     */
    private function isApprovalCandidate(Conversion $conversion): bool
    {
        return $conversion->status === ConversionStatus::Pending
            || ($conversion->status === ConversionStatus::Rejected
                && $conversion->rejected_reason === self::NETWORK_REJECTED);
    }

    /**
     * Null means the row was not a candidate when fraud was skipped, and the
     * lock has since moved it into one. Treating that as a duplicate costs a
     * retry rather than paying on a verdict we never reached.
     */
    private function fraudOutcome(?ConversionStatus $fraudStatus): ReconcileOutcome
    {
        return match ($fraudStatus) {
            ConversionStatus::Approved => ReconcileOutcome::Credit,
            ConversionStatus::Pending => ReconcileOutcome::HoldPending,
            ConversionStatus::Rejected => ReconcileOutcome::RejectFraud,
            default => ReconcileOutcome::Duplicate,
        };
    }

    /**
     * Apply one cell to the locked row. Everything here runs inside the lock.
     *
     * Returns the publisher callback the caller owes once the transaction
     * commits, or null when nothing is owed.
     */
    private function apply(
        ReconcileOutcome $outcome,
        Conversion $locked,
        ConversionStatus $incoming,
        ?string $fraudReason,
        PostbackDTO $dto,
        IncomingPostback $log,
    ): ?OutgoingPostbackKind {
        $result = IncomingPostbackResult::Accepted;
        $rejectReason = null;
        $notify = null;

        switch ($outcome) {
            case ReconcileOutcome::Credit:
                $this->credit($locked, $dto);
                $notify = OutgoingPostbackKind::Credit;
                break;

            case ReconcileOutcome::HoldPending:
                // Stays pending for the review queue. The upstream approval is
                // recorded below so an admin can see the network is waiting on us.
                break;

            case ReconcileOutcome::RejectFraud:
                $locked->update([
                    'status' => ConversionStatus::Rejected,
                    'rejected_reason' => $fraudReason ?? self::NETWORK_REJECTED,
                ]);
                break;

            case ReconcileOutcome::RejectNetwork:
                $locked->update([
                    'status' => ConversionStatus::Rejected,
                    'rejected_reason' => self::NETWORK_REJECTED,
                ]);
                break;

            case ReconcileOutcome::Reverse:
                // A pending or rejected conversion never credited anyone.
                // Charging it back would invent a debt out of a postback nobody
                // was paid for. A test row transitions and notifies but never
                // touches the ledger.
                $wasCredited = $locked->status === ConversionStatus::Approved;

                $locked->update([
                    'status' => ConversionStatus::Reversed,
                    'reversed_at' => now(),
                ]);

                if ($wasCredited && ! $locked->is_test) {
                    $this->chargeBack($locked);
                }

                $notify = $wasCredited ? OutgoingPostbackKind::Reversal : null;
                break;

            case ReconcileOutcome::AlertRejectedAfterCredit:
                $result = IncomingPostbackResult::Duplicate;

                // Only the first time the network says it. Partners retry a
                // postback until they get a 200 and some retry regardless, and
                // an alert that fires per retry is an alert an operator mutes.
                // The audit column is written below, so the second fire finds
                // `rejected` already recorded and stays quiet.
                if ($locked->upstream_status !== ConversionStatus::Rejected) {
                    AdminAlert::raise('conversion.upstream_rejected_after_credit', $locked, [
                        'trans_id' => (string) $locked->trans_id,
                        'network_txid' => (string) $locked->network_txid,
                        'network_id' => (int) $locked->network_id,
                        'payout' => (string) $locked->payout,
                    ]);
                }
                break;

            case ReconcileOutcome::RefuseInternalRejection:
                $result = IncomingPostbackResult::Rejected;
                $rejectReason = 'internal rejection stands';
                break;

            case ReconcileOutcome::Duplicate:
                $result = IncomingPostbackResult::Duplicate;
                break;
        }

        $this->touchUpstream($locked, $incoming);

        $log->update([
            'result' => $result,
            'reject_reason' => $rejectReason,
            'conversion_id' => $locked->id,
        ]);

        return $notify;
    }

    /**
     * Record what the network last said. Audit only — a plain update, never a
     * money write, and `updated_at` is left alone so this cannot be mistaken
     * for a change of status.
     */
    private function touchUpstream(Conversion $conversion, ConversionStatus $incoming): void
    {
        $conversion->forceFill([
            'upstream_status' => $incoming,
            'upstream_updated_at' => now(),
        ])->saveQuietly();
    }

    /**
     * The internal name for what a driver just told us.
     *
     * Drivers speak their network's vocabulary and the DTO carries it through;
     * `credited` and `approved` are the same state under two names, and an
     * audit column that holds both is an audit column nobody can query.
     */
    private function upstreamStatus(PostbackDTO $dto): ConversionStatus
    {
        return match ($dto->status) {
            'credited' => ConversionStatus::Approved,
            'rejected' => ConversionStatus::Rejected,
            'reversed' => ConversionStatus::Reversed,
            default => ConversionStatus::Pending,
        };
    }

    private function existing(PostbackDTO $dto, Network $network, bool $forUpdate = false): ?Conversion
    {
        // Global scopes off: a panel session carries `PublisherScope`, and a
        // reconciliation that cannot see the row would record a second one.
        return Conversion::query()
            ->withoutGlobalScopes()
            ->where('network_id', $network->id)
            ->where('network_txid', $dto->networkTxId)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * An admin clearing a conversion out of the review queue — docs/07 §7.
     *
     * This is the only path that turns a `pending` conversion into money, and
     * it writes the ledger entry and fires the publisher postback exactly like
     * the automatic path does. A second call is a no-op rather than a second
     * payment.
     */
    public function approvePending(Conversion $conversion, ?User $actor = null): Conversion
    {
        $approved = $this->transition($conversion, ConversionStatus::Approved, function (Conversion $locked): bool {
            if ($locked->status !== ConversionStatus::Pending) {
                return false;
            }

            // No DTO: an admin approval keeps the amounts the postback brought.
            // It still moves the hold, because the hold is measured from the
            // moment the money became real.
            $this->credit($locked, null);

            return true;
        });

        if ($approved !== true) {
            return $conversion->refresh();
        }

        AuditLog::record('conversion.approved', $conversion, ['status' => 'pending'], ['status' => 'approved'], $actor);

        $this->notifyPublisher($conversion->refresh(), OutgoingPostbackKind::Credit);

        return $conversion;
    }

    /**
     * Rejecting records the reason and moves nothing. No entry was ever written.
     *
     * Under the same lock as every other status change. This used to be a plain
     * check-then-act, which loses a race it is now genuinely exposed to: the
     * reconciler can approve a pending row from an upstream credit at any
     * moment, and an admin rejection reading a stale `pending` would overwrite
     * a status that has an earning behind it — a rejected conversion the
     * publisher was nevertheless paid for.
     */
    public function rejectPending(Conversion $conversion, string $reason, ?User $actor = null): Conversion
    {
        $rejected = $this->transition($conversion, ConversionStatus::Rejected, function (Conversion $locked) use ($reason): bool {
            if ($locked->status !== ConversionStatus::Pending) {
                return false;
            }

            $locked->update([
                'status' => ConversionStatus::Rejected,
                'rejected_reason' => $reason,
            ]);

            return true;
        });

        if ($rejected !== true) {
            return $conversion->refresh();
        }

        AuditLog::record('conversion.rejected', $conversion, ['status' => 'pending'], [
            'status' => 'rejected',
            'reason' => $reason,
        ], $actor);

        return $conversion->refresh();
    }

    /**
     * Admin-initiated chargeback — docs/07 §6.
     *
     * The same write as a network reversal, with a human's name and reason
     * attached. It exists for the case where we know the money is bad before
     * the network tells us.
     */
    public function forceReverse(Conversion $conversion, string $reason, ?User $actor = null): Conversion
    {
        $before = $conversion->status->value;

        $wasCredited = $this->transition($conversion, ConversionStatus::Reversed, function (Conversion $locked) use ($actor): bool {
            $wasCredited = $locked->status === ConversionStatus::Approved;

            $locked->update([
                'status' => ConversionStatus::Reversed,
                'reversed_at' => now(),
            ]);

            if ($wasCredited && ! $locked->is_test) {
                $this->chargeBack($locked, $actor);
            }

            return $wasCredited;
        });

        if ($wasCredited === null) {
            return $conversion->refresh();
        }

        AuditLog::record('conversion.force_reversed', $conversion, ['status' => $before], [
            'status' => 'reversed',
            'reason' => $reason,
        ], $actor);

        if ($wasCredited) {
            $this->notifyPublisher($conversion->refresh(), OutgoingPostbackKind::Reversal);
        }

        return $conversion;
    }

    /**
     * Run a status change against the row as it is *now*, not as the caller
     * last saw it.
     *
     * Every caller reads the conversion, checks its status, and then writes —
     * a check-then-act. Two reversal postbacks for one txid (networks retry,
     * and there is more than one worker), or two admins clicking Approve in
     * the same second, both pass the check and both write: two chargebacks
     * for one earning, or two earnings for one conversion. The unique index
     * settles duplicates on the credit path; this is the equivalent for the
     * paths that change an existing row. The lock serialises them and the
     * re-read makes the second one see what the first one did.
     *
     * Returns null when the row is already in `$target`, otherwise whatever
     * `$change` returned.
     *
     * @template T
     *
     * @param  callable(Conversion): T  $change
     * @return T|null
     */
    private function transition(Conversion $conversion, ConversionStatus $target, callable $change): mixed
    {
        return DB::transaction(function () use ($conversion, $target, $change): mixed {
            $locked = Conversion::query()
                ->withoutGlobalScopes()
                ->whereKey($conversion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === $target) {
                return null;
            }

            return $change($locked);
        });
    }

    /**
     * Turn a locked row into money. The one place an earning is written for a
     * conversion that already exists.
     *
     * Called with a DTO by the reconciler and without one by `approvePending()`,
     * and both fix something the old separate paths got wrong:
     *
     *   - **The amounts come from the confirming postback, not the first one.**
     *     A partner that fires `pending` at $0.00 and then `approved` at $0.42
     *     would otherwise pay the publisher the placeholder. `rev_share_applied`
     *     is read off the row, not off the publisher: it is the share that was
     *     quoted when the click converted, and a rev-share change since then
     *     must not rewrite a conversion the publisher already earned.
     *   - **The hold is measured from approval, not from arrival.** `clears_at`
     *     was stamped when the row was created; a conversion that sat pending
     *     through a month boundary would become withdrawable the instant it was
     *     approved, which is not what "clears on the 1st" means.
     *
     * The row is written before the ledger entry, because `createEarning()`
     * reads `payout` and `clears_at` off the model to build the entry.
     *
     * Returns whether a Credit postback is owed; the caller sends it after the
     * transaction commits.
     */
    private function credit(Conversion $locked, ?PostbackDTO $dto): bool
    {
        $attributes = [
            'status' => ConversionStatus::Approved,
            'rejected_reason' => null,
            'clears_at' => now()->startOfMonth()->addMonth(),
        ];

        if ($dto !== null) {
            $revenue = Money::format($dto->payout);
            $payout = Money::share($revenue, $locked->rev_share_applied);
            $app = App::query()->find($locked->app_id);

            $attributes['revenue'] = $revenue;
            $attributes['payout'] = $payout;

            // A trashed app leaves the stored figure alone rather than
            // converting at a rate of one. The USD columns above are the ones
            // the ledger reads; this is the publisher's display currency.
            if ($app !== null) {
                $attributes['currency_amount'] = Money::currency($payout, $app->currency_rate);
            }
        }

        $locked->update($attributes);

        // Test-mode conversions prove an integration works. They never reach a
        // balance, a chart, or an earnings figure.
        if (! $locked->is_test) {
            LedgerEntry::createEarning($locked);
        }

        return true;
    }

    /**
     * The debit and the open request it shrinks, in one transaction — docs/06
     * §4: the request froze its amount before this debit existed, and reducing
     * it here is the only way the two can never disagree.
     */
    private function chargeBack(Conversion $conversion, ?User $actor = null): void
    {
        $entry = LedgerEntry::createChargeback($conversion);

        app(WithdrawalService::class)->applyChargeback(
            Publisher::query()->findOrFail($conversion->publisher_id),
            $entry->amount,
            $actor,
        );
    }

    /** Re-queue the publisher callback for a conversion. Used by both panels. */
    public function resendPostback(Conversion $conversion, ?User $actor = null): bool
    {
        if (blank($conversion->app?->postback_url)) {
            return false;
        }

        $kind = $conversion->status === ConversionStatus::Reversed
            ? OutgoingPostbackKind::Reversal
            : OutgoingPostbackKind::Credit;

        AuditLog::record('conversion.postback_resent', $conversion, null, ['kind' => $kind->value], $actor);

        DispatchPublisherPostback::dispatch($conversion, $kind)->onQueue('postbacks');

        return true;
    }

    /**
     * Post-conversion fraud rules — docs/05-fraud-engine.md, docs/04 §1.
     *
     * A `block` rule rejects, a `flag` rule sends the conversion to the review
     * queue as pending, and everything else is approved. The pipeline records
     * a `fraud_event` for every rule that fired, blocking or not, so the
     * decision is explainable without re-running it.
     *
     * On the create path the conversion row does not exist yet — this decides
     * the status it is created with — so the click is the subject of those
     * events and the two rules that need a conversion (`duplicate_conversion`,
     * `no_click_match`) are inert by design: the `(network_id, network_txid)`
     * unique index settles the duplicate, and an unresolvable click has already
     * returned above.
     *
     * On the reconcile path the row does exist and is passed in. Neither of
     * those two rules changes its mind about it: `duplicate_conversion`
     * excludes the row under evaluation and only fires on a twin under a
     * *different* network, and `no_click_match` passes whenever a click was
     * resolved, which reconcile requires. What the row does change is the two
     * rules that would otherwise be measured against the wrong thing —
     * `CountsConversions` stops counting the row towards its own cap, and
     * `ImpossibleSpeedRule` measures from the original conversion instead of
     * from this retry.
     */
    protected function evaluateFraudRules(PostbackDTO $dto, OfferClick $click, ?Conversion $conversion = null): ConversionStatus
    {
        $this->fraudReason = null;

        $app = App::query()->find($click->app_id);
        $publisher = Publisher::query()->find($click->publisher_id);

        if ($app === null || $publisher === null) {
            return ConversionStatus::Approved;
        }

        $ip = $dto->ip ?? $click->ip;

        $context = new FraudContext(
            app: $app,
            endUserId: (string) ($dto->endUserId ?? $click->end_user_id),
            ip: $ip,
            // Resolved from the conversion's own IP, not copied off the click —
            // `conversion_geo_mismatch` exists to compare the two.
            country: blank($ip) ? null : app(IpIntelligence::class)->inspect($ip)->country,
            userAgent: $click->user_agent,
            offer: Offer::query()->find($click->offer_id),
            click: $click,
            conversion: $conversion,
            payout: Money::share(Money::format($dto->payout), Money::format($publisher->rev_share, 2)),
            // The postback's own address, never the click's fallback. The two
            // post-conversion IP rules exist to inspect whoever *completed* the
            // offer — a tracking URL copied out of the address bar is completed
            // by someone the click stage never saw — and `$ip` above cannot
            // tell those two apart. See FraudContext::$conversionIp.
            conversionIp: $dto->ip,
        );

        $decision = app(FraudPipeline::class)->evaluate(FraudStage::PostConversion, $context);

        if ($decision->blocked) {
            // docs/01 §7: `conversions.rejected_reason` holds the rule key.
            // It is internal — Decision::publicReason() is what a publisher or
            // end user ever sees.
            $this->fraudReason = $decision->reason;

            return ConversionStatus::Rejected;
        }

        if ($decision->triggered === []) {
            return ConversionStatus::Approved;
        }

        // Only a `flag` rule holds money back. A `score` rule is advisory by
        // definition — pending a conversion because one fired would make the
        // third action indistinguishable from the second.
        $flagged = FraudRule::query()
            ->whereIn('key', $decision->triggered)
            ->where('action', FraudAction::Flag)
            ->exists();

        return $flagged ? ConversionStatus::Pending : ConversionStatus::Approved;
    }

    /**
     * Hosted walls hand the partner `{sub_id}` and ask them to echo it. MyChips
     * keeps that UUID on `micro_reward` and replaces `click_id` (and drops
     * `aff_sub1`) on the offer the user actually completed. `user_id` is then
     * the only value that still points at our embed row.
     *
     * The fallback is only for a hosted wall, only for an `offer_id IS NULL`
     * row, and only when exactly one live row exists for that user on this
     * partner. Two apps sharing a user id is ambiguous — crediting either
     * would pay the wrong publisher.
     */
    private function resolveClick(PostbackDTO $dto, Network $network): ?OfferClick
    {
        if ($dto->clickId !== null) {
            $click = OfferClick::query()->withoutGlobalScopes()->where('click_id', $dto->clickId)->first();

            if ($click !== null) {
                return $click;
            }
        }

        if ($dto->endUserId === null || ! $network->hostsWall()) {
            return null;
        }

        $embeds = OfferClick::query()
            ->withoutGlobalScopes()
            ->where('network_id', $network->id)
            ->where('end_user_id', $dto->endUserId)
            ->whereNull('offer_id')
            ->where('is_test', false)
            ->limit(2)
            ->get();

        return $embeds->count() === 1 ? $embeds->first() : null;
    }

    private function resolveStatus(PostbackDTO $dto, OfferClick $click): ConversionStatus
    {
        return match ($dto->status) {
            'credited' => $this->evaluateFraudRules($dto, $click),
            'rejected' => ConversionStatus::Rejected,
            default => ConversionStatus::Pending,
        };
    }

    private function notifyPublisher(Conversion $conversion, OutgoingPostbackKind $kind): void
    {
        // No callback configured — the publisher polls the dashboard instead.
        // A legitimate configuration, not an error and not a queued job.
        if (blank($conversion->app?->postback_url)) {
            return;
        }

        DispatchPublisherPostback::dispatch($conversion, $kind)->onQueue('postbacks');
    }
}
