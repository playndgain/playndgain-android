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
use App\Support\TransactionId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

        $conversion = DB::transaction(function () use ($dto, $network, $log, $click, $app, $publisher, $offer, $revenue, $revShare, $payout, $status, $now): ?Conversion {
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
                    // NULL. The partner's name is the only true thing left to say.
                    'offer_name' => $dto->offerName ?? $offer->name ?? $network->name,
                    'revenue' => $revenue,
                    'payout' => $payout,
                    'rev_share_applied' => $revShare,
                    'currency_amount' => Money::currency($payout, $app->currency_rate),
                    'status' => $status,
                    'is_test' => $click->is_test,
                    'rejected_reason' => $status === ConversionStatus::Rejected
                        ? ($this->fraudReason ?? 'network_rejected')
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
                // Already processed. Networks retry; this is expected traffic,
                // not an error.
                $log->update(['result' => IncomingPostbackResult::Duplicate]);

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

        if ($conversion?->status === ConversionStatus::Approved) {
            $this->notifyPublisher($conversion, OutgoingPostbackKind::Credit);
        }

        return $conversion;
    }

    /**
     * A partner reverses when the advertiser rejects — fraud, a refund, a
     * duplicate install. Reversing a reversal is a no-op.
     */
    public function reverse(PostbackDTO $dto, Network $network, IncomingPostback $log): ?Conversion
    {
        $conversion = Conversion::query()
            ->withoutGlobalScopes()
            ->where('network_id', $network->id)
            ->where('network_txid', $dto->networkTxId)
            ->first();

        if ($conversion === null) {
            $log->update([
                'result' => IncomingPostbackResult::Error,
                'reject_reason' => 'no conversion for network_txid '.$dto->networkTxId,
            ]);

            return null;
        }

        $wasCredited = $this->transition($conversion, ConversionStatus::Reversed, function (Conversion $locked) use ($log): bool {
            // A pending or rejected conversion never credited anyone. Charging it
            // back would invent a debt out of a postback nobody was paid for.
            $wasCredited = $locked->status === ConversionStatus::Approved;

            $locked->update([
                'status' => ConversionStatus::Reversed,
                'reversed_at' => now(),
            ]);

            if ($wasCredited && ! $locked->is_test) {
                $this->chargeBack($locked);
            }

            $log->update([
                'result' => IncomingPostbackResult::Accepted,
                'conversion_id' => $locked->id,
            ]);

            return $wasCredited;
        });

        if ($wasCredited === null) {
            // Already reversed — by an earlier retry, or by the worker that held
            // the lock a moment ago. Either way the retry it is.
            $log->update([
                'result' => IncomingPostbackResult::Duplicate,
                'conversion_id' => $conversion->id,
            ]);

            return $conversion->refresh();
        }

        if ($wasCredited) {
            $this->notifyPublisher($conversion->refresh(), OutgoingPostbackKind::Reversal);
        }

        return $conversion;
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

            $locked->update(['status' => ConversionStatus::Approved, 'rejected_reason' => null]);

            if (! $locked->is_test) {
                LedgerEntry::createEarning($locked);
            }

            return true;
        });

        if ($approved !== true) {
            return $conversion->refresh();
        }

        AuditLog::record('conversion.approved', $conversion, ['status' => 'pending'], ['status' => 'approved'], $actor);

        $this->notifyPublisher($conversion->refresh(), OutgoingPostbackKind::Credit);

        return $conversion;
    }

    /** Rejecting records the reason and moves nothing. No entry was ever written. */
    public function rejectPending(Conversion $conversion, string $reason, ?User $actor = null): Conversion
    {
        if ($conversion->status !== ConversionStatus::Pending) {
            return $conversion;
        }

        $conversion->update([
            'status' => ConversionStatus::Rejected,
            'rejected_reason' => $reason,
        ]);

        AuditLog::record('conversion.rejected', $conversion, ['status' => 'pending'], [
            'status' => 'rejected',
            'reason' => $reason,
        ], $actor);

        return $conversion;
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
     * The conversion row does not exist yet — this decides the status it is
     * created with — so the click is the subject of those events and the two
     * rules that need a conversion (`duplicate_conversion`, `no_click_match`)
     * are inert on this path by design: the `(network_id, network_txid)`
     * unique index settles the duplicate, and an unresolvable click has
     * already returned above.
     */
    protected function evaluateFraudRules(PostbackDTO $dto, OfferClick $click): ConversionStatus
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
