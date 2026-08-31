<?php

namespace App\Http\Controllers;

use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Models\FraudRule;
use App\Models\Network;
use App\Models\Offer;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * The landing page. Only real numbers ship — a fabricated counter is the fastest
 * way to lose a publisher who checks (docs/09 §7).
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing', ['stats' => $this->stats()]);
    }

    /**
     * Cached for an hour: the figures move on the sync schedule, not per request,
     * and the homepage should not open a table scan for every visitor.
     *
     * An empty array falls through to the view's own defaults. The page is the
     * front door — it stays up when the database does not, and it renders before
     * anyone has run a migration.
     *
     * @return array<string, int|float>
     */
    private function stats(): array
    {
        try {
            return Cache::remember('landing.stats', 3600, fn (): array => [
                'offers' => Offer::query()->where('status', OfferStatus::Active)->count(),
                'countries' => $this->activeCountryCount(),
                'networks' => Network::query()->where('status', NetworkStatus::Active)->count(),
                'fraud_rules' => FraudRule::query()->where('enabled', true)->count(),
                'avg_payout' => $this->averageActivePayout(),
            ]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The gross payout the average live offer pays on a conversion — the basis
     * the payout calculator multiplies by. Rounded to cents here rather than at
     * render: the figure the visitor reads has to be the exact figure the
     * arithmetic used, or the estimate stops being reproducible by hand.
     *
     * `avg()` comes back as a string from a DECIMAL column, and as null when no
     * offer is active. Both fold to 0.0, which is the honest answer — a
     * catalogue with nothing live cannot promise a payout.
     */
    private function averageActivePayout(): float
    {
        return round((float) Offer::query()->where('status', OfferStatus::Active)->avg('payout'), 2);
    }

    /**
     * `offers.countries` is a JSON array per offer, so distinct-across-rows is a
     * PHP fold rather than a DISTINCT. One column, once an hour.
     */
    private function activeCountryCount(): int
    {
        return Offer::query()
            ->where('status', OfferStatus::Active)
            ->pluck('countries')
            ->flatten()
            ->filter()
            ->unique()
            ->count();
    }
}
