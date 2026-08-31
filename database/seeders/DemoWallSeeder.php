<?php

namespace Database\Seeders;

use App\Enums\AppPlatform;
use App\Enums\AppStatus;
use App\Enums\ConversionType;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Enums\PublisherStatus;
use App\Enums\UserRole;
use App\Models\App;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * A publisher, an app, and enough inventory to render the offerwall locally.
 *
 * Deliberately NOT in DatabaseSeeder's list: `db:seed` on a real environment
 * must never create an approved publisher nobody signed up as. Run it by hand:
 *
 *     php artisan db:seed --class=DemoWallSeeder
 *
 * It carries its own offers rather than leaning on synced network inventory,
 * and everything in it is worldwide and device-agnostic so the wall renders
 * the same on any machine and in any browser.
 *
 * A local box has no GeoLite2 on disk, so the country resolves to null — and
 * the wall is fail-closed all the way: no country, no offers, not even the
 * worldwide ones. That is why the app is created in test mode and why the URL
 * printed at the end carries `&country=US`. Without it the demo wall is the
 * "we could not confirm your device" screen, correctly.
 *
 * Idempotent: re-running updates the same rows.
 */
class DemoWallSeeder extends Seeder
{
    private const PUBLISHER_EMAIL = 'demo@advalut.test';

    private const APP_NAME = 'Demo Wall';

    /** Gradient stops for the generated demo art — see icon(). */
    private const ICON_COLORS = [
        [[99, 102, 241], [168, 85, 247]],
        [[236, 72, 153], [251, 146, 60]],
        [[16, 185, 129], [59, 130, 246]],
        [[245, 158, 11], [239, 68, 68]],
        [[14, 165, 233], [99, 102, 241]],
        [[132, 204, 22], [22, 163, 74]],
    ];

    /**
     * name, instructions, payout USD, categories, featured, multi-reward, goals
     *
     * Goals are the steps the detail view lists, in USD like `offers.payout`,
     * and they sum to the offer's payout — a wall whose steps do not add up to
     * the headline figure is the one thing an end user will notice instantly.
     * A single-step offer carries none: the offer is the step.
     */
    private const OFFERS = [
        ['Coin Master', 'Reach village level 10', 0.64, ['Games'], true, false, []],
        ['Royal Match', 'Pass level 50 within 7 days', 8.75, ['Games'], true, true, [
            ['Install and open the game', 0.75],
            ['Reach level 10', 1.50],
            ['Reach level 25', 2.50],
            ['Reach level 50 within 7 days', 4.00],
        ]],
        ['Monopoly Go', 'Reach 1,000 net worth', 5.60, ['Games'], false, false, []],
        ['Shopee Shopping', 'Install and make a first purchase', 2.23, ['Shopping'], false, true, [
            ['Install and create an account', 0.48],
            ['Make a first purchase of $5 or more', 1.75],
        ]],
        ['TikTok Lite', 'Watch for 10 minutes a day', 1.44, ['Social'], false, true, [
            ['Install and sign in', 0.34],
            ['Watch 10 minutes on day 1', 0.35],
            ['Watch 10 minutes on day 3', 0.35],
            ['Watch 10 minutes on day 7', 0.40],
        ]],
        ['Survey — 8 minutes', 'Answer honestly, no speeding', 0.87, ['Surveys'], false, true, [
            ['Qualify for the survey', 0.12],
            ['Complete every question', 0.75],
        ]],
        ['Badoo — Dating & Chat', 'Complete signup and verify your photo', 0.63, ['Social'], false, false, []],
        ['Temu', 'Sign up with a new account', 0.29, ['Shopping'], false, false, []],
        ['Bingo Blitz', 'Complete the tutorial', 0.11, ['Games'], false, false, []],
        ['Nubank', 'Open an account and activate the card', 12.40, ['Finance'], false, true, [
            ['Open an account', 4.40],
            ['Activate the card', 3.00],
            ['Make a first transaction', 5.00],
        ]],
        ['Duolingo', null, 0.42, ['Education'], false, false, []],
        ['A very long offer name that has to truncate on a narrow card', 'And an instruction line that also runs past the card width', 0.05, ['Other'], false, false, []],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoWallSeeder refuses to run in production.');

            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => self::PUBLISHER_EMAIL],
            [
                'name' => 'Demo Publisher',
                'password' => 'password',
                'role' => UserRole::Publisher,
                'email_verified_at' => now(),
            ],
        );

        // updateOrCreate, not firstOrCreate: re-running has to converge on the
        // state declared here. firstOrCreate silently ignores every attribute
        // once the row exists, so a flag added to this file would never reach
        // a box that had already seeded once.
        $publisher = Publisher::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => 'Demo Publisher Ltd',
                'country' => 'EG',
                'status' => PublisherStatus::Approved,
                'rev_share' => 70.00,
                'min_payout' => 20.00,
                'approved_at' => now(),
            ],
        );

        // `driver` is unique and every registered key already has a row, so the
        // demo inventory gets the test double. NetworkServiceProvider registers
        // it outside production, which is what keeps a demo click from ending
        // in an UnknownDriverException.
        $network = Network::query()->updateOrCreate(
            ['driver' => 'fake'],
            [
                'name' => 'Demo Network',
                'status' => NetworkStatus::Active,
                'priority' => 100,
            ],
        );

        $app = App::query()->updateOrCreate(
            ['publisher_id' => $publisher->id, 'name' => self::APP_NAME],
            [
                'url' => 'https://demo.advalut.test',
                'platform' => AppPlatform::All,
                'status' => AppStatus::Active,
                'currency_name' => 'Coins',
                'currency_rate' => 1000.0000,
                // So `&country=XX` is honoured and the synced catalogue is
                // reachable on a box with no GeoLite2 on disk — docs/03 §7.
                'test_mode' => true,
                'approved_at' => now(),
            ],
        );

        foreach (self::OFFERS as $i => [$name, $instructions, $payout, $categories, $featured, $multi, $goals]) {
            $offer = Offer::query()->updateOrCreate(
                ['network_id' => $network->id, 'external_id' => 'demo-'.($i + 1)],
                [
                    'name' => $name,
                    'description' => $instructions ?? $name,
                    'instructions' => $instructions,
                    'tracking_url' => 'https://example.test/click?offer={offer_id}&subid={click_id}',
                    'payout' => $payout,
                    'conversion_type' => ConversionType::Cpi,
                    // Worldwide and device-agnostic on purpose — see the class docblock.
                    'countries' => [],
                    'devices' => [],
                    'categories' => $categories,
                    'is_featured' => $featured,
                    'is_multi_reward' => $multi,
                    'goals' => $goals === [] ? null : array_map(
                        fn (array $goal): array => ['name' => $goal[0], 'payout' => $goal[1]],
                        $goals,
                    ),
                    'status' => OfferStatus::Active,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ],
            );

            $this->icon($offer);
        }

        // Test mode pins a $0.01 offer above the wall and runs a real
        // conversion through it (docs/03 §7). Setting it is safe here and
        // nowhere else: this seeder refuses to run outside local.
        Setting::set('test_offer_id', (string) Offer::query()
            ->where('network_id', $network->id)
            ->where('external_id', 'demo-9')
            ->value('id'));

        // `country` is not optional here: no GeoLite2 on a local box means no
        // country, and no country means an empty wall.
        $url = rtrim((string) config('app.url'), '/')."/wall?appid={$app->app_id}&userid=USER_1&country=US";

        $this->command->info('Demo publisher: '.self::PUBLISHER_EMAIL.' / password');
        $this->command->info('Wall: '.$url);
        $this->command->info('Test mode is on, so try: &country=DE · &theme=light · &lang=ar');
    }

    /**
     * A demo wall with no art is a demo of the fallback tile, not of the wall.
     *
     * Written straight into the icon proxy's own cache path so it is served
     * with no outbound request — a local box is often offline, and pointing
     * `icon_url` at a real CDN would make the demo depend on someone else's
     * uptime. Real inventory arrives with `icon_url` set and the proxy fetches
     * it once (docs/03 §6); nothing here changes that path.
     */
    private function icon(Offer $offer): void
    {
        $path = "offer-icons/{$offer->id}.png";

        if (! extension_loaded('gd') || Storage::disk('local')->exists($path)) {
            return;
        }

        $size = 128;
        [$from, $to] = self::ICON_COLORS[crc32($offer->name) % count(self::ICON_COLORS)];
        $canvas = imagecreatetruecolor($size, $size);

        // Vertical blend, one allocated colour per row. 128 rows is well inside
        // a truecolor image's palette and cheaper than a per-pixel loop.
        for ($y = 0; $y < $size; $y++) {
            $t = $y / ($size - 1);
            $colour = imagecolorallocate(
                $canvas,
                (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t),
                (int) round($from[2] + ($to[2] - $from[2]) * $t),
            );
            imageline($canvas, 0, $y, $size, $y, $colour);
        }

        // GD's built-in faces top out around 9×15px, so the initial is drawn
        // small and resampled up — blurrier than a real glyph, and still the
        // difference between a wall of art and a wall of flat squares.
        $glyph = imagecreatetruecolor(11, 17);
        imagealphablending($glyph, false);
        imagesavealpha($glyph, true);
        imagefill($glyph, 0, 0, imagecolorallocatealpha($glyph, 0, 0, 0, 127));
        imagealphablending($glyph, true);
        imagestring($glyph, 5, 1, 1, mb_strtoupper(mb_substr($offer->name, 0, 1)), imagecolorallocate($glyph, 255, 255, 255));

        // Alpha has to survive the scale-up or the letter arrives inside an
        // opaque block of its own background.
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $glyph, 40, 30, 0, 0, 48, 68, 11, 17);

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();

        Storage::disk('local')->put($path, $png);
        $offer->update(['icon_url' => 'seeded://'.$offer->id]);
    }
}
