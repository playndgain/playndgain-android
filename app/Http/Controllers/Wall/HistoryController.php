<?php

namespace App\Http\Controllers\Wall;

use App\Enums\ConversionStatus;
use App\Http\Controllers\Controller;
use App\Models\Conversion;
use App\Models\OfferClick;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\WallContext;
use App\Services\Wall\WallGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /wall/history` — this end user's own clicks and what each one earned.
 *
 * Steps 1–8 are `WallGate`, in full and in the same order as `WallController`.
 * Step 7 is the one worth naming here too: a user who declined data processing
 * must not reach their own click history by deep link, and the history of a
 * user is exactly the data they declined. `Cache-Control: private, no-store`
 * comes from `RendersWallPages` and is not optional on a page that is per end
 * user by definition.
 *
 *   9.  This user's click rows, newest 50, paired with their conversions
 *   10. Plus what the embedded partner walls paid, told to us by postback
 *   11. Render
 */
class HistoryController extends Controller
{
    use RendersWallPages;

    /** One screen of scrolling. Beyond it a user is reading a report, not a history. */
    private const ROWS = 50;

    public function __construct(
        private readonly WallGate $gate,
    ) {}

    public function __invoke(Request $request): Response
    {
        $app = $this->gate->app($request);

        if (! $this->gate->isServable($app)) {
            return $this->notice('unavailable', status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $context = $this->gate->context($request, $app);

        if ($this->gate->consentRequired($context->country)) {
            $consent = $request->input('consent') === 'review'
                ? null
                : $this->gate->consent($app, $context->endUserId);

            if ($consent === null) {
                return $this->page('consent', ['context' => $context]);
            }

            if (! $consent->granted) {
                return $this->notice('declined', $context);
            }
        }

        if ($this->gate->isBanned($app, $context->endUserId)) {
            return $this->notice('banned', $context);
        }

        return $this->page('history', [
            'context' => $context,
            'rows' => $this->rows($context),
        ]);
    }

    /**
     * Two sources, one list. Ours is a click that may have earned; a partner's
     * is a payout with no click of ours behind it.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(WallContext $context): array
    {
        $rows = [...$this->clickRows($context), ...$this->partnerRows($context)];

        // Sorted here rather than in SQL: two queries against two tables, and
        // the union of 50 and 50 is small enough that the database has nothing
        // to add.
        usort($rows, fn (array $a, array $b): int => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        return array_slice($rows, 0, self::ROWS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clickRows(WallContext $context): array
    {
        /** @var Collection<int, OfferClick> $clicks */
        $clicks = OfferClick::query()
            // The wall is stateless: there is no authenticated publisher for
            // `BelongsToPublisher` to resolve against. Scoping is the two
            // `where`s below, and they are the whole answer — this app, and
            // this end user.
            ->withoutGlobalScopes()
            ->where('app_id', $context->app->id)
            ->where('end_user_id', $context->endUserId)
            // The partner-wall embed row is an `offer_clicks` row with a null
            // `offer_id`, one per (app, partner, end user), reused on every
            // later open — so its `created_at` is the first open and never
            // moves. Rendered here it would be a row with no offer name and a
            // timestamp that lies. What it earned is still real money, and it
            // reaches the list as its own rows through `partnerRows()`.
            ->whereNotNull('offer_id')
            ->where('is_test', false)
            ->with('offer:id,name,icon_url')
            ->latest('created_at')
            ->limit(self::ROWS)
            ->get();

        if ($clicks->isEmpty()) {
            return [];
        }

        $conversions = $this->conversions($clicks->pluck('id')->all());

        return $clicks
            ->map(fn (OfferClick $click): array => $this->row($click, $conversions->get((int) $click->id) ?? new Collection))
            ->all();
    }

    /**
     * What the embedded partner walls paid — offers we never listed, described
     * to us entirely by the partner's postback.
     *
     * There is no click of ours per offer: a partner's wall is one frame, and
     * the embed row behind it is one row per (app, partner, end user), reused
     * on every later open. So the **conversion** is the row — its own name, its
     * own time, its own status. Read off the click instead, every partner row
     * would carry the day the user first opened the frame.
     *
     * Reached through the embed click ids rather than `conversions.end_user_id`:
     * `ConversionProcessor` writes that column as `$dto->endUserId ?? $click->end_user_id`,
     * and the first half is whatever the partner echoed back at us.
     *
     * A partner row has no "started" state, and that is correct: a partner
     * tells us about a completion or about nothing at all.
     *
     * @return list<array<string, mixed>>
     */
    private function partnerRows(WallContext $context): array
    {
        $embedIds = OfferClick::query()
            ->withoutGlobalScopes()
            ->where('app_id', $context->app->id)
            ->where('end_user_id', $context->endUserId)
            ->whereNull('offer_id')
            ->pluck('id')
            ->all();

        if ($embedIds === []) {
            return [];
        }

        return Conversion::query()
            ->withoutGlobalScopes()
            ->whereIn('offer_click_id', $embedIds)
            ->where('is_test', false)
            ->with('network:id,name')
            ->latest('created_at')
            ->limit(self::ROWS)
            ->get()
            ->map(fn (Conversion $conversion): array => [
                // No offer of ours, so no icon route to point the tile at.
                'id' => null,
                // Snapshotted from the postback at conversion time. Some
                // partners send an offer name here and some send the event that
                // paid; it is what they told us, and inventing a nicer one
                // would be inventing.
                'name' => (string) $conversion->offer_name,
                'has_icon' => false,
                'at' => $conversion->created_at,
                'status' => $conversion->status->value,
                'partner' => (string) $conversion->network->name,
                'reward' => $conversion->status === ConversionStatus::Approved
                    ? OfferFeed::rewardLabel((float) $conversion->currency_amount)
                    : null,
                'reason' => $conversion->status === ConversionStatus::Rejected
                    ? ($conversion->rejected_reason ?: null)
                    : null,
            ])
            ->all();
    }

    /**
     * One grouped query for the conversions of those click ids, keyed in PHP.
     *
     * Deliberately not `OfferClick::conversion()`, which is a `hasOne`: a
     * multi-reward offer pays **per goal**, so one click legitimately carries
     * several conversion rows, and `hasOne` would silently show the first one it
     * happened to load and hide the rest of the money.
     *
     * @param  list<int>  $clickIds
     * @return \Illuminate\Support\Collection<int, Collection<int, Conversion>>
     */
    private function conversions(array $clickIds): \Illuminate\Support\Collection
    {
        return Conversion::query()
            ->withoutGlobalScopes()
            ->whereIn('offer_click_id', $clickIds)
            ->orderBy('id')
            ->get(['id', 'offer_click_id', 'status', 'currency_amount', 'rejected_reason'])
            // Keyed as an int, not as whatever the driver returned: the lookup
            // is by `offer_clicks.id`, and a string key silently matches
            // nothing on a page that would then show every row as "Started".
            ->groupBy(fn (Conversion $conversion): int => (int) $conversion->offer_click_id);
    }

    /**
     * What a row says: the offer, when, its status, and the reward when there
     * is one.
     *
     * Several conversions against one click is the multi-reward case, and the
     * row answers it the way the user would ask it — the **sum** of what
     * cleared, under the status of the **newest** goal, because that is where
     * they are in the offer.
     *
     * @param  Collection<int, Conversion>  $conversions
     * @return array<string, mixed>
     */
    private function row(OfferClick $click, Collection $conversions): array
    {
        $latest = $conversions->last();

        $credited = $conversions
            ->where('status', ConversionStatus::Approved)
            ->sum(fn (Conversion $conversion): float => (float) $conversion->currency_amount);

        return [
            'id' => $click->offer_id,
            'name' => (string) ($click->offer->name ?? ''),
            'has_icon' => filled($click->offer?->icon_url),
            // Ours, from our own catalogue. Only a partner row names a partner.
            'partner' => null,
            'at' => $click->created_at,
            // No conversion at all is its own state, and it is the common one:
            // clicked, and nothing back from the network yet.
            'status' => $latest?->status->value ?? 'started',
            // Only when there is money. `0` under a rejected row is a figure
            // that says nothing and reads like a balance.
            'reward' => $credited > 0 ? OfferFeed::rewardLabel((float) $credited) : null,
            'reason' => $latest?->status === ConversionStatus::Rejected
                ? ($latest->rejected_reason ?: null)
                : null,
        ];
    }
}
