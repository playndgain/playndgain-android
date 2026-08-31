<?php

namespace App\Http\Controllers\Wall;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The hosted offerwall — docs/03-offerwall.md §2.
 *
 * Render order, and the reason it is this order: steps 1–9 are cheap and must
 * stay cheap, so everything that can refuse the request does so before step 10,
 * the only step allowed near the offers table.
 *
 *   1. Resolve app by public app_id                    → 404
 *   2. app active AND publisher approved               → unavailable page
 *   3. Verify sig when require_signed_wall             → 403
 *   4. Validate userid                                 → 400
 *   5. Country from IP  6. Device from UA
 *   7. Consent gate     8. Banned end user
 *   9. IP protection gate                              → 403
 *   10. Eligible offers (Redis) 11. Minus completed 12. Sort/limit 13. Render
 */
class WallController extends Controller
{
    use RendersWallPages;

    /** Everything else, including nothing at all, is the default order. */
    public const SORTS = ['reward', 'trending'];

    public function __construct(
        private readonly WallGate $gate,
        private readonly OfferFeed $feed,
    ) {}

    public function __invoke(Request $request): Response
    {
        $app = $this->gate->app($request);

        if (! $this->gate->isServable($app)) {
            return $this->notice('unavailable', status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $context = $this->gate->context($request, $app);

        // Not merely "was consent required" — `escalate()` below must fire
        // only once a real "yes" is in hand, never on the strength of the
        // displayed country alone. That country can be a test-mode
        // `?country=` override (see WallContext::$countryOverridden), so
        // gating on `consentRequired($context->country)` at the escalate call
        // site would ask a paid provider about a real IP the user was never
        // asked to consent for.
        $consentGranted = false;

        if ($this->gate->consentRequired($context->country)) {
            // `consent=review` is how a user reopens their own answer; without
            // it a declined consent is permanent and the gate is a trap.
            $consent = $request->input('consent') === 'review'
                ? null
                : $this->gate->consent($app, $context->endUserId);

            if ($consent === null) {
                return $this->page('consent', ['context' => $context]);
            }

            if (! $consent->granted) {
                return $this->notice('declined', $context);
            }

            $consentGranted = true;
        }

        if ($this->gate->isBanned($app, $context->endUserId)) {
            return $this->notice('banned', $context);
        }

        if ($consentGranted) {
            $context = $this->gate->escalate($context);
        }

        // Last, not first: a user with no consent decision belongs on the
        // consent screen, and a protection block written ahead of it would put
        // their IP and end user ID in a fraud_event before they answered.
        if ($blocked = $this->gate->protectionBlock($context)) {
            return $this->notice($blocked, $context, Response::HTTP_FORBIDDEN);
        }

        // Whitelisted here rather than trusted downstream: the value comes off a
        // URL the end user can edit, and it ends up in a `selected` attribute.
        $sort = in_array($request->input('sort'), self::SORTS, true)
            ? (string) $request->input('sort')
            : null;

        $offers = $this->feed->for($context, $sort);

        return $this->page('index', [
            'context' => $context,
            'offers' => $offers,
            'sort' => $sort,
            'categories' => $this->categories($offers),
            // Two ways to have no offers, and they are not the same sentence.
            // "Nothing in your country right now" is a lie when the country is
            // exactly what we failed to establish.
            'unplaced' => $context->country === null || $context->device === DeviceType::Other,
        ]);
    }

    /**
     * Filter chips are built from what is actually on the wall — a chip that
     * filters to nothing is worse than no chip.
     *
     * @param  list<array<string, mixed>>  $offers
     * @return list<string>
     */
    private function categories(array $offers): array
    {
        $categories = [];

        foreach ($offers as $offer) {
            foreach ((array) $offer['categories'] as $category) {
                if (is_string($category) && $category !== '') {
                    $categories[$category] = true;
                }
            }
        }

        ksort($categories);

        return array_keys($categories);
    }
}
