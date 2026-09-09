<?php

namespace Database\Seeders;

use App\Enums\ConversionStatus;
use App\Enums\OfferStatus;
use App\Enums\OutgoingPostbackKind;
use App\Enums\OutgoingPostbackStatus;
use App\Models\App;
use App\Models\Conversion;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Publisher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Thirty days of traffic for the demo publisher, so the dashboard has
 * something on it: clicks, conversions, a few reversals, the ledger rows they
 * produce, the postbacks that reported them, and the daily aggregates the
 * reporting pages read. The landing page's product captures come from this
 * account (scripts/capture-landing.mjs).
 *
 * Local only, like DemoWallSeeder, and run after it:
 *
 *     php artisan db:seed --class=DemoWallSeeder
 *     php artisan db:seed --class=DemoActivitySeeder
 *
 * Deterministic — the same seed draws the same month, so a screenshot taken
 * twice is the same screenshot — and idempotent: re-running replaces the demo
 * account's activity rather than doubling it.
 */
class DemoActivitySeeder extends Seeder
{
    private const PUBLISHER_EMAIL = 'demo@playndgain.test';

    private const DAYS = 30;

    private const SEED = 20260901;

    /** Share of traffic by country, in percent. */
    private const COUNTRIES = ['US' => 34, 'DE' => 12, 'GB' => 10, 'BR' => 9, 'IN' => 8, 'FR' => 6, 'CA' => 5, 'MX' => 4, 'PH' => 4, 'ID' => 3, 'ES' => 3, 'AU' => 2];

    private const DEVICES = ['android' => 58, 'ios' => 30, 'desktop' => 12];

    private const AGENTS = [
        'android' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36',
        'ios' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
    ];

    private const OS = ['android' => 'Android 14', 'ios' => 'iOS 17.5', 'desktop' => 'Windows 11'];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoActivitySeeder refuses to run in production.');

            return;
        }

        $user = User::query()->where('email', self::PUBLISHER_EMAIL)->first();
        $publisher = $user === null ? null : Publisher::query()->withoutGlobalScopes()->where('user_id', $user->id)->first();
        $app = $publisher === null ? null : App::query()->withoutGlobalScopes()->where('publisher_id', $publisher->id)->orderBy('id')->first();

        if ($publisher === null || $app === null) {
            $this->command->error('No demo app: run DemoWallSeeder first.');

            return;
        }

        // The demo catalogue, minus the pinned test offer and the truncation
        // fixture — neither is an offer anyone would complete.
        $offers = Offer::query()
            ->whereHas('network', fn ($query) => $query->where('driver', 'fake'))
            ->where('status', OfferStatus::Active)
            ->whereNotIn('external_id', ['demo-9', 'demo-12'])
            ->orderBy('id')
            ->get();

        if ($offers->isEmpty()) {
            $this->command->error('No demo offers: run DemoWallSeeder first.');

            return;
        }

        mt_srand(self::SEED);

        $this->reset($app, $publisher);

        $today = CarbonImmutable::today();
        $now = CarbonImmutable::now();
        $revShare = (float) $publisher->rev_share;
        $rate = (float) $app->currency_rate;

        // Featured offers draw more clicks; everything else shares the rest.
        $weights = $offers->mapWithKeys(fn (Offer $offer): array => [$offer->id => $offer->is_featured ? 22 : 8])->all();
        $byId = $offers->keyBy('id');

        $clicks = 0;
        $ledger = [];
        $postbacks = [];
        $conversionCount = 0;
        $reversalCount = 0;

        for ($back = self::DAYS; $back >= 0; $back--) {
            $day = $today->subDays($back);

            // A month that grows a little, with busier weekends — it is a game.
            $base = 140 + (self::DAYS - $back) * 3;
            $base *= $day->isWeekend() ? 1.25 : 1.0;
            $base *= 0.85 + mt_rand(0, 30) / 100;

            if ($back === 0) {
                $base *= max(0.05, $now->hour / 24);
            }

            $rows = [];
            $converting = [];

            for ($i = 0, $n = (int) round($base); $i < $n; $i++) {
                $device = $this->pick(self::DEVICES);
                $offer = $byId[$this->pick($weights)];
                $seconds = $back === 0 ? mt_rand(0, max(1, $now->secondOfDay - 1)) : mt_rand(0, 86399);
                $at = $day->addSeconds($seconds);
                $blocked = mt_rand(1, 100) <= 2;

                $rows[] = [
                    'click_id' => (string) Str::uuid(),
                    'app_id' => $app->id,
                    'publisher_id' => $publisher->id,
                    'offer_id' => $offer->id,
                    'network_id' => $offer->network_id,
                    'end_user_id' => 'USER_'.mt_rand(1, 600),
                    'ip' => '203.0.113.'.mt_rand(1, 254),
                    'country' => $this->pick(self::COUNTRIES),
                    'user_agent' => self::AGENTS[$device],
                    'device_type' => $device,
                    'os' => self::OS[$device],
                    'browser' => $device === 'ios' ? 'Safari' : 'Chrome',
                    'referrer' => null,
                    'risk_score' => $blocked ? mt_rand(70, 95) : mt_rand(0, 25),
                    'blocked' => $blocked,
                    'blocked_reason' => $blocked ? 'datacenter_ip' : null,
                    'is_test' => false,
                    'converted_at' => null,
                    'created_at' => $at,
                ];

                // Roughly one click in sixteen becomes a conversion.
                if (! $blocked && mt_rand(1, 100) <= 6) {
                    $converting[] = count($rows) - 1;
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('offer_clicks')->insert($chunk);
            }

            $clicks += count($rows);

            // Read the ids back by click_id: the conversions reference them.
            $ids = DB::table('offer_clicks')
                ->whereIn('click_id', array_map(fn (int $i): string => $rows[$i]['click_id'], $converting))
                ->pluck('id', 'click_id');

            foreach ($converting as $i) {
                $click = $rows[$i];
                $offer = $byId[$click['offer_id']];
                $at = CarbonImmutable::parse($click['created_at'])->addMinutes(mt_rand(2, 90));

                if ($at->greaterThan($now)) {
                    continue;
                }

                $revenue = (float) $offer->payout;
                $payout = round($revenue * $revShare / 100, 6);
                $reversedAt = mt_rand(1, 100) <= 5 ? $at->addDays(mt_rand(1, 5))->addMinutes(mt_rand(0, 600)) : null;

                if ($reversedAt !== null && $reversedAt->greaterThan($now)) {
                    $reversedAt = null;
                }

                $conversion = Conversion::query()->create([
                    'trans_id' => 'OFFER'.$offer->id.'-'.strtoupper(Str::random(12)),
                    'network_txid' => (string) mt_rand(10000000, 99999999),
                    'click_id' => $click['click_id'],
                    'offer_click_id' => $ids[$click['click_id']],
                    'app_id' => $app->id,
                    'publisher_id' => $publisher->id,
                    'offer_id' => $offer->id,
                    'network_id' => $offer->network_id,
                    'end_user_id' => $click['end_user_id'],
                    'offer_name' => $offer->name,
                    'revenue' => $revenue,
                    'payout' => $payout,
                    'rev_share_applied' => $revShare,
                    'currency_amount' => round($payout * $rate, 4),
                    'status' => $reversedAt === null ? ConversionStatus::Approved : ConversionStatus::Reversed,
                    'is_test' => false,
                    'ip' => $click['ip'],
                    'country' => $click['country'],
                    // The product's own rule (ConversionProcessor): a month's
                    // earnings clear together on the first of the next month.
                    'clears_at' => $at->startOfMonth()->addMonth(),
                    'reversed_at' => $reversedAt,
                    'created_at' => $at,
                    'updated_at' => $reversedAt ?? $at,
                ]);

                DB::table('offer_clicks')->where('id', $ids[$click['click_id']])->update(['converted_at' => $at]);

                $conversionCount++;
                $ledger[] = [$at, 'earning', $conversion];
                $postbacks[] = $this->postback($conversion, OutgoingPostbackKind::Credit, $at->addSeconds(1));

                if ($reversedAt !== null) {
                    $reversalCount++;
                    $ledger[] = [$reversedAt, 'chargeback', $conversion];
                    $postbacks[] = $this->postback($conversion, OutgoingPostbackKind::Reversal, $reversedAt->addSeconds(1));
                }
            }
        }

        // Ledger rows in the order the money moved, so every balance_after is
        // the balance at that moment. LedgerEntry stamps `now()`; the query
        // builder puts the real time back without waking the model's guards.
        usort($ledger, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($ledger as [$at, $kind, $conversion]) {
            $entry = $kind === 'earning'
                ? LedgerEntry::createEarning($conversion)
                : LedgerEntry::createChargeback($conversion);

            DB::table('ledger_entries')->where('id', $entry->id)->update(['created_at' => $at]);
        }

        foreach (array_chunk($postbacks, 500) as $chunk) {
            DB::table('outgoing_postbacks')->insert($chunk);
        }

        Artisan::call('stats:aggregate', ['--days' => self::DAYS + 1]);

        $this->command->info(sprintf(
            'Demo activity: %s clicks, %s conversions (%s reversed), %s postbacks over %d days for %s.',
            number_format($clicks),
            number_format($conversionCount),
            number_format($reversalCount),
            number_format(count($postbacks)),
            self::DAYS + 1,
            self::PUBLISHER_EMAIL,
        ));
    }

    /**
     * The demo account's activity, gone. Query builder throughout: the ledger
     * model refuses deletes on purpose, and this is the one place a demo row
     * is allowed to disappear.
     */
    private function reset(App $app, Publisher $publisher): void
    {
        DB::table('outgoing_postbacks')->where('app_id', $app->id)->delete();
        DB::table('ledger_entries')->where('publisher_id', $publisher->id)->delete();
        DB::table('conversions')->where('app_id', $app->id)->delete();
        DB::table('offer_clicks')->where('app_id', $app->id)->delete();
        DB::table('daily_stats')->where('publisher_id', $publisher->id)->delete();
    }

    /**
     * One callback row. Most land first time in well under a second; a few
     * hit a publisher's server that is having a bad minute.
     *
     * @return array<string, mixed>
     */
    private function postback(Conversion $conversion, OutgoingPostbackKind $kind, CarbonImmutable $at): array
    {
        $failed = mt_rand(1, 100) <= 4;

        return [
            'conversion_id' => $conversion->id,
            'app_id' => $conversion->app_id,
            'kind' => $kind->value,
            'url' => 'https://demo.playndgain.test/postback',
            'method' => 'GET',
            'payload' => json_encode([
                'trans_id' => $conversion->trans_id,
                'user_id' => $conversion->end_user_id,
                'payout_usd' => (string) $conversion->payout,
                'currency_amount' => $kind === OutgoingPostbackKind::Credit ? (string) $conversion->currency_amount : '-'.$conversion->currency_amount,
                'status' => $kind === OutgoingPostbackKind::Credit ? 'approved' : 'reversed',
            ]),
            'attempt' => 1,
            'http_status' => $failed ? 500 : 200,
            'response_body' => $failed ? 'Internal Server Error' : 'ok',
            'duration_ms' => $failed ? mt_rand(900, 4800) : mt_rand(38, 260),
            'status' => $failed ? OutgoingPostbackStatus::Failed->value : OutgoingPostbackStatus::Success->value,
            'error' => $failed ? 'HTTP 500' : null,
            'next_retry_at' => null,
            'created_at' => $at,
        ];
    }

    /**
     * A weighted draw. mt_rand, seeded once in run(), keeps it reproducible.
     *
     * @param  array<string|int, int>  $weights
     */
    private function pick(array $weights): string|int
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
