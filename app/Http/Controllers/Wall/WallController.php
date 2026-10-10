<?php

namespace App\Http\Controllers\Wall;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\WallContext;
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

        // Steps 7–9, shared with the SDK API so the two cannot drift. See
        // WallGate::admit() for why the order is what it is.
        $admission = $this->gate->admit($request, $context, reviewConsent: $request->input('consent') === 'review');
        $context = $admission->context;

        return match ($admission->refusal) {
            null => $this->render($request, $context),
            'consent' => $this->page('consent', ['context' => $context]),
            'declined' => $this->notice('declined', $context),
            'banned' => $this->notice('banned', $context),
            default => $this->notice($admission->refusal, $context, Response::HTTP_FORBIDDEN),
        };
    }

    /** Steps 10–13: the offers, for a request the gate admitted. */
    private function render(Request $request, WallContext $context): Response
    {
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
            'categories' => self::categories($offers),
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
    public static function categories(array $offers): array
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
