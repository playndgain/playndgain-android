<?php

namespace App\Http\Controllers\Wall;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\Wall\EmbedClick;
use App\Services\Wall\PartnerWall;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A partner's own hosted wall, framed inside ours — docs/03-offerwall.md §2.
 *
 * Steps 1–8 are `WallGate`, in the same order and for the same reasons as
 * `WallController`. Step 7 is the one worth naming: an embedded third-party
 * wall sets third-party cookies, so a European user who declined must not
 * reach one, including by deep link.
 *
 *   9.  Resolve the partner — active, https wall URL   → empty (index) / 404 (named)
 *   10. Catalogue of cards, until `?open=1`
 *   11. Pre-click fraud + the embed click row
 *   12. Blocked                                        → notice
 *   13. Substitute macros, render the frame
 */
class PartnerWallController extends Controller
{
    use RendersWallPages;

    public function __construct(
        private readonly WallGate $gate,
        private readonly EmbedClick $clicks,
    ) {}

    /** The tab itself: whichever partner sorts first. */
    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, Network $network): Response
    {
        return $this->render($request, $network);
    }

    private function render(Request $request, ?Network $network): Response
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
            $this->gate->observeIdentity($request, $context);

            return $this->notice('banned', $context);
        }

        $this->gate->observeIdentity($request, $context);

        $partners = PartnerWall::catalogue($app);

        // The route-bound network still has to be one of them: `embeddable()`
        // is a query, and a hand-typed ID is not.
        $partner = $network === null
            ? $partners->first()
            : $partners->firstWhere('id', $network->id);

        if ($partner === null) {
            // The tab is hidden when the catalogue is empty. A named partner
            // that does not qualify is still a 404; a direct visit to the
            // empty tab is an empty, the way History is when nothing has
            // started.
            abort_if($network !== null, Response::HTTP_NOT_FOUND);

            return $this->page('partner', [
                'context' => $context,
                'partner' => null,
                'partners' => $partners,
                'src' => null,
            ]);
        }

        // The tab is a catalogue. Opening writes the embed row and loads
        // the frame — visiting the tab is not a click.
        if (! $request->boolean('open')) {
            return $this->page('partner', [
                'context' => $context,
                'partner' => $partner,
                'partners' => $partners,
                'src' => null,
            ]);
        }

        [$click, $blocked] = $this->clicks->open($context, $partner);

        if ($blocked) {
            // Its own copy, not `blocked`: that one tells the user to go back
            // and pick a different offer, and on this tab there is no other
            // offer — there is one partner wall and the offers tab.
            return $this->notice('partner_blocked', $context, Response::HTTP_FORBIDDEN);
        }

        return $this->page('partner', [
            'context' => $context,
            'partner' => $partner,
            'partners' => $partners,
            'src' => PartnerWall::url($partner, $context, (string) $click->click_id),
        ]);
    }
}
