<?php

namespace App\Http\Controllers\Wall;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Services\Wall\ConsentRecorder;
use App\Services\Wall\HistoryFeed;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\SdkPayload;
use App\Services\Wall\WallContext;
use App\Services\Wall\WallGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/sdk/v1/*` — the native Android and iOS SDKs. docs/03 §9.
 *
 * The same gate, in the same order, as the HTML wall: steps 1–6 are
 * `WallGate::app/isServable/context` and steps 7–9 are `WallGate::admit()`,
 * which `WallController` calls too. Nothing about fraud is decided here. Clicks
 * do not happen here either: the SDK opens `click_url`, which is `/click/{offer}`,
 * so every pre-click rule, the click row and the postback path are the iframe's.
 *
 * Only what `SdkPayload` lists leaves this controller. No upstream payout, no
 * network identity.
 */
class SdkController extends Controller
{
    public function __construct(
        private readonly WallGate $gate,
        private readonly OfferFeed $feed,
    ) {}

    public function wall(Request $request): JsonResponse
    {
        return $this->offers($request, $this->admit($request));
    }

    /**
     * The offers body for an admission `admit()` already decided.
     *
     * @param  array{0: ?WallContext, 1: ?JsonResponse}  $admitted
     */
    private function offers(Request $request, array $admitted): JsonResponse
    {
        [$context, $refusal] = $admitted;

        if ($refusal !== null) {
            return $refusal;
        }

        $sort = in_array($request->input('sort'), WallController::SORTS, true)
            ? (string) $request->input('sort')
            : null;

        $rows = $this->feed->for($context, $sort);
        $unplaced = $context->country === null || $context->device === DeviceType::Other;

        return $this->respond($unplaced && $rows === [] ? 'unplaced' : 'ok', $context, data: [
            'offers' => array_map(fn (array $row): array => SdkPayload::offer($row, $context), $rows),
            'categories' => WallController::categories($rows),
            'sort' => $sort,
        ]);
    }

    public function offer(Request $request, Offer $offer): JsonResponse
    {
        $app = $this->gate->app($request);

        if (! $this->gate->isServable($app)) {
            return $this->respond('unavailable', null, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $context = $this->gate->context($request, $app);

        // OfferDetailController's own checks, its own codes: a detail URL is a
        // deep link, never a first visit, so no consent screen here.
        if ($this->gate->consentRequired($context->country)) {
            $consent = $this->gate->consent($app, $context->endUserId);

            if ($consent === null || ! $consent->granted) {
                return $this->respond('declined', $context, Response::HTTP_FORBIDDEN);
            }
        }

        if ($this->gate->isBanned($app, $context->endUserId)) {
            $this->gate->observeIdentity($request, $context);

            return $this->respond('banned', $context, Response::HTTP_FORBIDDEN);
        }

        $this->gate->observeIdentity($request, $context);

        if (! $offer->isLive()
            || ! $this->feed->servesTargeting($context, $offer)
            || $this->feed->isBlockedForPublisher($context, $offer)) {
            return $this->respond('offer_gone', $context, Response::HTTP_GONE);
        }

        $reward = OfferFeed::rewardLabel($this->feed->convert($context, (float) $offer->payout));

        return $this->respond('ok', $context, data: SdkPayload::detail(
            $offer,
            $context,
            $reward,
            $this->feed->steps($context, $offer),
            $this->feed->popularity([(int) $offer->id])[$offer->id] ?? 0,
        ));
    }

    public function history(Request $request): JsonResponse
    {
        // Same flags as HistoryController: no render gate, no re-lookup.
        [$context, $refusal] = $this->admit($request, protect: false, escalate: false);

        if ($refusal !== null) {
            return $refusal;
        }

        return $this->respond('ok', $context, data: [
            'rows' => array_map(fn (array $row): array => [
                'offer_id' => $row['id'],
                'name' => (string) $row['name'],
                'icon_url' => ($row['has_icon'] ?? false) && $row['id'] !== null ? url('/img/offer/'.$row['id']) : null,
                'at' => $row['at']?->toIso8601String(),
                'status' => (string) $row['status'],
                'status_label' => __('wall.history.status.'.$row['status']),
                'reward_label' => $row['reward'],
                'reason' => $row['reason'],
                'partner' => $row['partner'],
            ], HistoryFeed::rows($context)),
        ]);
    }

    public function consent(Request $request): JsonResponse
    {
        $app = $this->gate->app($request);

        if (! $this->gate->isServable($app)) {
            return $this->respond('unavailable', null, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $context = $this->gate->context($request, $app);
        $action = $request->input('action');

        abort_unless(in_array($action, ['accept', 'decline'], true), Response::HTTP_BAD_REQUEST);

        ConsentRecorder::record($context, $action === 'accept');

        // The same body `wall` returns now, so a granted consent lands on the
        // offers and a declined one on the notice through one code path. The
        // context is reused (one IP lookup per request, as on the HTML wall),
        // and `consent=review` is ignored: the answer was just recorded, and
        // re-opening it here would loop the user back onto the consent screen.
        return $this->offers($request, $this->admit($request, context: $context, reviewConsent: false));
    }

    /**
     * Steps 1–9. Returns the admitted context, or the response for a refusal.
     *
     * @return array{0: ?WallContext, 1: ?JsonResponse}
     */
    private function admit(
        Request $request,
        bool $protect = true,
        bool $escalate = true,
        ?WallContext $context = null,
        ?bool $reviewConsent = null,
    ): array {
        if ($context === null) {
            $app = $this->gate->app($request);

            if (! $this->gate->isServable($app)) {
                return [null, $this->respond('unavailable', null, Response::HTTP_SERVICE_UNAVAILABLE)];
            }

            $context = $this->gate->context($request, $app);
        }

        $admission = $this->gate->admit(
            $request,
            $context,
            protect: $protect,
            reviewConsent: $reviewConsent ?? $request->input('consent') === 'review',
            escalate: $escalate,
        );
        $context = $admission->context;

        return match ($admission->refusal) {
            null => [$context, null],
            'consent' => [$context, $this->respond('consent_required', $context)],
            'declined' => [$context, $this->respond('declined', $context)],
            'banned' => [$context, $this->respond('banned', $context)],
            default => [$context, $this->respond('blocked', $context, Response::HTTP_FORBIDDEN, notice: $admission->refusal)],
        };
    }

    /**
     * The envelope every SDK response shares.
     *
     * @param  array<string, mixed>|null  $data
     */
    private function respond(
        string $status,
        ?WallContext $context,
        int $code = Response::HTTP_OK,
        ?array $data = null,
        ?string $notice = null,
    ): JsonResponse {
        if ($context !== null) {
            app()->setLocale($context->locale);
        }

        $noticeKey = $notice ?? match ($status) {
            'declined', 'banned', 'blocked', 'unavailable', 'offer_gone' => $status,
            default => null,
        };

        $body = array_filter([
            'status' => $status,
            'app' => $context !== null ? SdkPayload::app($context) : null,
            'strings' => $context !== null ? SdkPayload::strings($context) : null,
            'notice' => $noticeKey !== null ? [
                'key' => $noticeKey,
                'title' => __("wall.notice.{$noticeKey}.title"),
                'body' => __("wall.notice.{$noticeKey}.body"),
            ] : null,
            'data' => $data,
        ], fn (mixed $value): bool => $value !== null);

        return response()->json($body, $code)->header('Cache-Control', 'private, no-store');
    }
}
