<?php

namespace App\Http\Controllers\Wall;

use App\Enums\FraudStage;
use App\Fraud\FraudPipeline;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Networks\NetworkDriverManager;
use App\Services\Wall\IpAnonymizer;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\TestConversion;
use App\Services\Wall\WallContext;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Click handling — docs/03-offerwall.md §3.
 *
 * Two properties that are easy to lose and expensive to notice:
 *   - **The click row is written even when blocked.** A blocked click with no
 *     row is a support question nobody can answer.
 *   - **302, never 301.** A 301 is cached permanently by the browser, and the
 *     tracking URL carries a single-use click_id — a cached redirect would
 *     attach the second click's conversion to the first click's row.
 *
 * The one refusal that writes nothing is the consent gate: storing IP, user
 * agent and publisher user ID for a user who has not consented — or has
 * declined — is exactly the processing the gate exists to prevent.
 */
class ClickController extends Controller
{
    use RendersWallPages;

    public function __construct(
        private readonly WallGate $gate,
        private readonly FraudPipeline $pipeline,
        private readonly NetworkDriverManager $networks,
        private readonly OfferFeed $feed,
        private readonly TestConversion $testConversion,
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

            $context = $this->gate->escalate($context);
        }

        if (! $offer->isLive()) {
            return $this->notice('offer_gone', $context, Response::HTTP_GONE);
        }

        // A publisher's own block has to hold on a hand-crafted URL too, or it
        // is a suggestion. See OfferFeed::isBlockedForPublisher().
        if ($this->feed->isBlockedForPublisher($context, $offer)) {
            return $this->notice('offer_gone', $context, Response::HTTP_GONE);
        }

        // The test offer is not inventory. It never appears on a live wall, so
        // a click on it from an app that is not in test mode arrived by hand —
        // and following its tracking URL would send a real user to our own docs.
        if (! $context->testMode && OfferFeed::isTestOffer($offer)) {
            return $this->notice('offer_gone', $context, Response::HTTP_GONE);
        }

        $click = $this->record($context, $offer);

        if ($click->blocked) {
            return $this->notice('blocked', $context, Response::HTTP_FORBIDDEN);
        }

        // The test offer converts here rather than at an advertiser — docs/03
        // §7. It is the one offer on the wall with nowhere to redirect to, and
        // sending the integrator on a round trip they cannot complete is the
        // opposite of what the feature is for.
        //
        // Keyed on the offer, not on `is_test`: a click can now be test
        // earnings without being the test offer (see record()), and those still
        // belong at the network.
        if ($context->testMode && OfferFeed::isTestOffer($offer)) {
            $this->testConversion->fire($click, $offer);

            return $this->notice('test_fired', $context);
        }

        try {
            $url = $this->networks->make($offer->network)->buildTrackingUrl($offer, (string) $click->click_id);
        } catch (Throwable $e) {
            // A driver without a tracking URL is our problem, not the user's,
            // and it must not surface as a 500 on the busiest route we own.
            Log::error('Tracking URL build failed', ['offer' => $offer->id, 'error' => $e->getMessage()]);

            return $this->notice('offer_gone', $context, Response::HTTP_BAD_GATEWAY);
        }

        // 302. See the class docblock — a 301 here silently misattributes
        // every conversion after the first.
        return redirect()->away($url, Response::HTTP_FOUND);
    }

    /**
     * The full pre-click decision, then the row. Evaluating first is what
     * docs/03 §3 specifies: the rules describe the click, so the row they
     * produce carries their verdict instead of being edited after the fact.
     */
    private function record(WallContext $context, Offer $offer): OfferClick
    {
        $banned = $this->gate->isBanned($context->app, $context->endUserId);
        $decision = $this->pipeline->evaluate(FraudStage::PreClick, $context->fraudContext($offer));

        return OfferClick::create([
            'click_id' => (string) Str::uuid(),
            'app_id' => $context->app->id,
            'publisher_id' => $context->app->publisher_id,
            'offer_id' => $offer->id,
            'network_id' => $offer->network_id,
            'end_user_id' => $context->endUserId,
            'ip' => $context->app->anonymize_ip ? IpAnonymizer::mask($context->ip) : $context->ip,
            'country' => $context->country,
            'user_agent' => $context->userAgent,
            'device_type' => $context->device,
            'os' => $context->os,
            'browser' => $context->browser,
            'referrer' => $context->referrer,
            'risk_score' => $banned ? max($decision->score, 100) : $decision->score,
            'blocked' => $banned || $decision->blocked,
            'blocked_reason' => $banned ? 'banned_end_user' : $decision->reason,
            // Test earnings, for two reasons rather than one. The test offer is
            // the obvious case. The other is a click whose country came from
            // `?country=XX`: that override is honoured only in test mode, but
            // test mode is a publisher-editable toggle on a live app and the
            // parameter is readable off the iframe URL, so an app left in test
            // mode would otherwise let anyone earn real money on geo-targeted
            // offers from a country the targeting never allowed. Those are the
            // conversions a network charges back, and the publisher's balance
            // should never have contained them.
            'is_test' => $context->testMode
                && (OfferFeed::isTestOffer($offer) || $context->countryOverridden),
            'created_at' => now(),
        ]);
    }
}
