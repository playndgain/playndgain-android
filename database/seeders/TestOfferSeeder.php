<?php

namespace Database\Seeders;

use App\Enums\ConversionType;
use App\Enums\OfferStatus;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Setting;
use App\Support\Identity;
use Illuminate\Database\Seeder;

/**
 * The $0.01 test offer — docs/03-offerwall.md §7.
 *
 * The wall pins this above everything while an app is in test mode, and the
 * spec ends that section with "It is not optional." Until now it was: the row
 * only ever existed in `DemoWallSeeder`, which refuses to run outside local, so
 * a production `migrate --seed` produced a `settings.test_offer_id` that was
 * never set and a feature reachable only through tinker.
 *
 * It lives on the `direct` network because that is where platform inventory
 * belongs — offers we own rather than a partner's catalogue. `direct` never
 * syncs (`DirectDriver::syncsCatalogue()` is false), so no nightly run can
 * deactivate this row for having stopped appearing in a feed it was never in.
 *
 * The tracking URL is our own click route rather than an advertiser's: a click
 * on this offer is intercepted before the redirect (see ClickController) and
 * converted server-side through the real pipeline, so the URL is a fallback
 * that should never be followed.
 */
class TestOfferSeeder extends Seeder
{
    public const EXTERNAL_ID = 'playndgain-test-offer';

    public function run(): void
    {
        $network = Network::query()->where('driver', 'direct')->first();

        if ($network === null) {
            // NetworkSeeder owns that row. Running this seeder alone against a
            // database without it should say so rather than create a second
            // half-built `direct` network.
            $this->command->warn('No `direct` network — run NetworkSeeder first. Test offer skipped.');

            return;
        }

        $offer = Offer::query()->updateOrCreate(
            ['network_id' => $network->id, 'external_id' => self::EXTERNAL_ID],
            [
                'name' => 'Test Offer',
                'description' => 'A $0.01 offer that runs a real conversion through the full pipeline.',
                'instructions' => 'Tap to fire a real test conversion: fraud rules, ledger entry, and your postback.',
                'tracking_url' => Identity::url().'/docs/testing',
                'payout' => '0.010000',
                'conversion_type' => ConversionType::Other,
                // Worldwide and device-agnostic: whoever is integrating is the
                // one clicking it, and they are wherever they are.
                'countries' => [],
                'devices' => [],
                'categories' => [],
                'is_featured' => true,
                'is_multi_reward' => true,
                'status' => OfferStatus::Active,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        Setting::set('test_offer_id', (string) $offer->id);
    }
}
