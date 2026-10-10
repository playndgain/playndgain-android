<?php

namespace App\Http\Controllers\Wall;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/wall/offer/{offer}` — what the offer asks of the user, before they leave
 * the wall for it.
 *
 * The wall's card is a headline: a name, one line, one figure. Everything a
 * user actually decides on — the steps, what each step is worth, which devices
 * and countries it is open to, how long it takes — had nowhere to live. That
 * cost conversions on exactly the inventory worth the most: nobody starts a
 * two-week game offer on the strength of "reach village level 10".
 *
 * It refuses everything a click refuses, and for the same reasons: the offer id
 * is in a URL the end user can read off the iframe, so a publisher's blacklist
 * has to hold here too or it is advisory (see OfferFeed::isBlockedForPublisher).
 * The one thing it deliberately does NOT do is write an OfferClick — reading
 * the terms is not a click, and counting it as one would inflate every CTR on
 * the platform and misprice the inventory.
 *
 * The offer row is read straight from the database rather than from the wall's
 * cached payload. Goals belong to one offer and are read once per detail view;
 * putting them in the per-triple payload would grow a hot Redis value that
 * every list render pays for, to carry data only this page reads.
 */
class OfferDetailController extends Controller
{
    use RendersWallPages;

    public function __construct(
        private readonly WallGate $gate,
        private readonly OfferFeed $feed,
    ) {}

    public function __invoke(Request $request, Offer $offer): Response
    {
        $app = $this->gate->app($request);

        if (! $this->gate->isServable($app)) {
            return $this->notice('unavailable', status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $context = $this->gate->context($request, $app);

        if ($this->gate->consentRequired($context->country)) {
            $consent = $this->gate->consent($app, $context->endUserId);

            if ($consent === null || ! $consent->granted) {
                return $this->notice('declined', $context, Response::HTTP_FORBIDDEN);
            }
        }

        if ($this->gate->isBanned($app, $context->endUserId)) {
            $this->gate->observeIdentity($request, $context);

            return $this->notice('banned', $context, Response::HTTP_FORBIDDEN);
        }

        $this->gate->observeIdentity($request, $context);

        // Targeting is re-checked here and nowhere else on this route's path:
        // the wall filters its list in SQL, but this URL carries an offer id
        // the end user can read off their own iframe, and a copied link would
        // otherwise render another country's offer — terms, steps and a live
        // start button — to someone who can never complete it.
        if (! $offer->isLive()
            || ! $this->feed->servesTargeting($context, $offer)
            || $this->feed->isBlockedForPublisher($context, $offer)) {
            return $this->notice('offer_gone', $context, Response::HTTP_GONE);
        }

        $data = [
            'context' => $context,
            'offer' => $offer,
            'reward' => OfferFeed::rewardLabel($this->feed->convert($context, (float) $offer->payout)),
            'steps' => $this->feed->steps($context, $offer),
            // The same figure the card carries, so the screen the user landed
            // on confirms the one they tapped. This page does not read the
            // cached payload, so it runs the count for its one offer id.
            'people' => $this->feed->popularity([(int) $offer->id])[$offer->id] ?? 0,
        ];

        // Same URL, two shapes. The wall's dialog fetches the fragment; a user
        // with JS off — or one who opened the link in a new tab — gets the page.
        return $this->page($request->boolean('fragment') ? 'partials.offer' : 'offer', $data);
    }
}
