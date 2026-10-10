<?php

namespace App\Services\Wall;

use App\Enums\BlacklistType;
use App\Enums\ConversionStatus;
use App\Enums\DeviceType;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Models\App;
use App\Models\Blacklist;
use App\Models\Conversion;
use App\Models\Network;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Models\Setting;
use App\Services\Money\Money;
use App\Support\AppNetworks;
use App\Support\Plain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Steps 9–11 of the render pipeline. Step 9 is the only one allowed near the
 * offers table, and normally it does not reach it — the eligible payload for an
 * `app:country:device` triple is one Redis read.
 *
 * What is cached is what is identical for every user of that triple. The two
 * filters that are per-user — the OS floor and the offers they already
 * completed — run in PHP against the cached list.
 */
class OfferFeed
{
    private const TTL = 300;

    private const COMPLETED_TTL = 60;

    /** The payload is trimmed per user afterwards, so it holds more than the wall shows. */
    private const PAYLOAD_SIZE = 500;

    /**
     * @param  string|null  $sort  `reward`, `trending`, or anything else for the
     *                             default order. Applied in PHP over the cached
     *                             payload rather than in SQL: sorting in the
     *                             query would key the cache by sort order and
     *                             triple the number of payloads for no gain on a
     *                             list already capped at `wall_offers_limit`.
     * @return list<array<string, mixed>>
     */
    public function for(WallContext $context, ?string $sort = null): array
    {
        // Fail closed on a user we could not place, and closed before anything
        // else — including test mode's pinned offer, which would otherwise be
        // the one card an unplaced user is shown.
        //
        // Serving the worldwide inventory to an address GeoLite2 does not know,
        // or to a user agent that parses to no device, is serving offers to
        // someone whose eligibility was never established — and the offer's own
        // terms are what a network charges back against, not ours. An empty
        // wall costs one session; a wall of impossible offers costs the
        // conversions *and* the relationship with the network.
        if ($context->country === null || $context->device === DeviceType::Other) {
            return [];
        }

        $completed = $this->completedOfferIds($context);
        $limit = max(1, (int) Setting::get('wall_offers_limit', 100));
        $rows = [];

        // Sorted before the loop, not after it: the loop stops at
        // `wall_offers_limit`, so reordering afterwards would only shuffle the
        // first N of the default order and `?sort=reward` would never reach the
        // high payout sitting at position 300 of the payload.
        foreach ($this->sorted($this->eligible($context), $sort) as $offer) {
            if (! $this->meetsOsFloor(is_string($offer['os_min_version']) ? $offer['os_min_version'] : null, $context->osVersion)) {
                continue;
            }

            if (! $offer['is_multi_reward'] && in_array((int) $offer['id'], $completed, true)) {
                continue;
            }

            $rows[] = $this->display($offer, $context);

            if (count($rows) >= $limit) {
                break;
            }
        }

        $test = $context->testMode ? $this->testOffer($context) : null;

        if ($test !== null) {
            // Pinned above the wall, and pinned once. `settings.test_offer_id`
            // can name a row that is also servable to this app, and the same
            // card twice — the second one without the badge — reads as a bug
            // in the wall rather than a choice in the settings table.
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['id'] !== $test['id']));

            array_unshift($rows, $test);
        }

        return $rows;
    }

    /**
     * The header's `<select>`, applied to the payload.
     *
     * An unknown value falls back to the default order rather than erroring —
     * the parameter is in a URL the end user can edit, and a 500 on a typo is a
     * worse answer than the wall they asked for.
     *
     * `usort` is stable in PHP 8, so every tie keeps the cached order beneath
     * it: featured first, then payout, then network priority.
     *
     * @param  list<array<string, mixed>>  $offers
     * @return list<array<string, mixed>>
     */
    private function sorted(array $offers, ?string $sort): array
    {
        if ($sort === 'reward') {
            usort($offers, fn (array $a, array $b): int => $b['payout'] <=> $a['payout']);
        }

        if ($sort === 'trending') {
            // Payout is the tiebreak, and it has to be: a new catalogue is
            // mostly zeroes, and without it "trending" is the default order
            // with the featured pin silently removed.
            usort($offers, fn (array $a, array $b): int => [$b['people'] ?? 0, $b['payout']] <=> [$a['people'] ?? 0, $a['payout']]);
        }

        return $offers;
    }

    /**
     * How many people started each of these offers in the last 30 days — the
     * figure beside the device icons on a card.
     *
     * Four decisions, and each one is the difference between social proof and
     * noise: distinct end users rather than rows, because the glyph is a person
     * and raw rows are a number a bored user can inflate alone; `blocked = 0`,
     * because a fraud refusal is not proof; `is_test = 0`, because the
     * publisher's own integration testing is not a user; and 30 days, because a
     * lifetime count freezes — an offer that ran hot last year outranks the one
     * converting today, permanently, and no new offer can ever catch up.
     *
     * Cross-app on purpose. It is a property of the offer, not of one
     * publisher's traffic, and a per-app count would read "0" on every card of a
     * new app's wall — the exact moment the number is least helpful.
     *
     * Public because the detail view does not read the cached payload (docs/03
     * §5) and runs the same count for the one offer it holds.
     *
     * @param  list<int>  $offerIds
     * @return array<int, int>
     */
    public function popularity(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        return OfferClick::query()
            // The wall is stateless — there is no authenticated publisher for
            // `BelongsToPublisher` to resolve against, and this count spans
            // every one of them by design.
            ->withoutGlobalScopes()
            ->whereIn('offer_id', $offerIds)
            ->where('blocked', false)
            ->where('is_test', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('offer_id')
            ->selectRaw('offer_id, COUNT(DISTINCT end_user_id) as people')
            ->pluck('people', 'offer_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eligible(WallContext $context): array
    {
        // `v2` is the payload shape, not the app: the keys the cards read grew
        // (devices, people), and a 300-second-old value from the deploy before
        // this one would be missing them on every card it renders.
        $key = sprintf('wall:offers:v2:%d:%s:%s', $context->app->id, $context->country ?? 'xx', $context->device->value);

        /** @var list<array<string, mixed>> */
        return Cache::tags($this->tags($context->app))->remember(
            $key,
            self::TTL,
            fn (): array => $this->build($context),
        );
    }

    /**
     * The tag set must be computed identically on read and write or every read
     * misses: the store namespaces the key by the tags it was given. Hence a
     * fixed rule — the app, plus every active network — rather than tags
     * derived from whatever the payload happened to contain.
     *
     * @return list<string>
     */
    private function tags(App $app): array
    {
        /** @var list<string> $networks */
        $networks = Cache::remember(
            'wall:active-networks',
            60,
            fn (): array => Network::query()
                ->where('status', NetworkStatus::Active)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): string => "network:{$id}")
                ->all(),
        );

        return ['app:'.$app->id, ...$networks];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(WallContext $context): array
    {
        $app = $context->app;
        $blacklist = $this->blacklist($app);

        // A blacklisted device or country empties the wall rather than
        // filtering it — every offer would fail the same check, and the empty
        // state names the cause.
        if (in_array($context->device->value, $blacklist[BlacklistType::Device->value], true)) {
            return [];
        }

        if ($context->country !== null && (
            in_array($context->country, $blacklist[BlacklistType::Country->value], true)
            || in_array($context->country, (array) ($app->blocked_countries ?? []), true)
        )) {
            return [];
        }

        $query = Offer::query()
            ->select('offers.*')
            ->join('networks', 'networks.id', '=', 'offers.network_id')
            ->where('networks.status', NetworkStatus::Active)
            ->where('offers.status', OfferStatus::Active)
            // Sub-cent payouts round to nothing in the publisher's currency and
            // produce support tickets instead of rewards.
            ->where('offers.payout', '>=', 0.01)
            ->where(fn (Builder $inner) => $this->targeting($inner, 'offers.countries', $context->country))
            ->where(fn (Builder $inner) => $this->targeting($inner, 'offers.devices', $context->device->value))
            ->orderByDesc('offers.is_featured')
            ->orderByDesc('offers.payout')
            ->orderByDesc('networks.priority')
            ->limit(self::PAYLOAD_SIZE);

        if ($blacklist[BlacklistType::Offer->value] !== []) {
            $query->whereNotIn('offers.id', $blacklist[BlacklistType::Offer->value]);
        }

        // The test offer reaches the wall through testOffer() and no other way.
        // It is featured, worldwide, device-agnostic and pays exactly the $0.01
        // floor, so the day an admin enables the `direct` network to sell a real
        // offer it would otherwise appear at the top of every wall in every geo
        // — paying real users a cent of real money to prove nothing.
        $testOfferId = Setting::get('test_offer_id');

        if (is_numeric($testOfferId)) {
            $query->where('offers.id', '!=', (int) $testOfferId);
        }

        $blockedNetworks = AppNetworks::resolveIds($blacklist[BlacklistType::Network->value]);

        if ($blockedNetworks !== []) {
            $query->whereNotIn('networks.id', $blockedNetworks);
        }

        $offers = $query->get();

        // One extra query per cache build, none per render — the count rides
        // the payload and its 300-second TTL, and five minutes of staleness on
        // a popularity figure is invisible.
        $people = $this->popularity($offers->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $offers
            ->map(fn (Offer $offer): array => [
                'id' => $offer->id,
                'name' => $offer->name,
                // Flattened here rather than in the view: the payload is built
                // once per five minutes and rendered on every load, and a card
                // is the wrong place to be stripping someone else's markup.
                'instructions' => Plain::text($offer->instructions),
                'payout' => (float) $offer->payout,
                'os_min_version' => $offer->os_min_version,
                'is_multi_reward' => (bool) $offer->is_multi_reward,
                // The count, never the goals themselves. This payload is cached
                // per app:country:device and read on every render; the steps are
                // read once, by the detail view, straight from the row.
                'goal_count' => count(array_filter((array) ($offer->goals ?? []), 'is_array')),
                'is_featured' => (bool) $offer->is_featured,
                'categories' => array_values(array_filter((array) ($offer->categories ?? []), 'is_string')),
                // The card's facts row. An empty list is "any device" and says
                // so there rather than drawing three glyphs nobody reads.
                'devices' => array_values(array_filter((array) ($offer->devices ?? []), 'is_string')),
                'people' => $people[$offer->id] ?? 0,
                'has_icon' => filled($offer->icon_url),
                'is_test' => false,
            ])
            ->all();
    }

    /**
     * Empty targeting means "everywhere" — an offer with no country list is
     * open to this user's country too, so it is not "someone else's offer" and
     * it stays. A user we could not place never reaches here at all: `build()`
     * returns an empty wall above rather than falling back to the everywhere
     * inventory.
     */
    private function targeting(Builder $query, string $column, ?string $value): void
    {
        $query->whereNull($column)->orWhereJsonLength($column, 0);

        if ($value !== null) {
            $query->orWhereJsonContains($column, $value);
        }
    }

    /**
     * The same rule, for one offer the caller already holds.
     *
     * The wall's list is filtered in SQL, but the detail route takes an offer
     * id out of a URL the end user can read off their own iframe. Without this
     * a copied link renders another country's offer, with its terms and a
     * working start button, to someone who can never complete it.
     *
     * Not applied at click time on purpose: a click that arrives for the wrong
     * geo is a fraud signal with a score and an event (`geo_mismatch`), not a
     * flat refusal — see isBlockedForPublisher().
     */
    public function servesTargeting(WallContext $context, Offer $offer): bool
    {
        if ($context->country === null || $context->device === DeviceType::Other) {
            return false;
        }

        return self::listAllows($offer->countries, $context->country)
            && self::listAllows($offer->devices, $context->device->value);
    }

    /** Empty or absent list = open to everyone. */
    private static function listAllows(mixed $list, string $value): bool
    {
        $list = array_values(array_filter((array) ($list ?? []), 'is_string'));

        return $list === [] || in_array($value, $list, true);
    }

    /**
     * @return array<string, list<string>>
     */
    /**
     * Is this offer refused for this request by something the publisher set?
     *
     * The wall filters these out, but the click route takes an offer id and the
     * end user knows every parameter it needs — they read appid, userid and sig
     * out of the iframe URL they are looking at. Without this the blacklist is
     * advisory: a publisher who blocked an offer still gets the conversion,
     * still owes their user the reward, and still carries whatever they blocked
     * it for. docs/03 §3's sample click handler omits the check; that is the
     * spec being brief about the happy path, not a decision that a block should
     * be bypassable.
     *
     * Targeting (country/device on the offer itself) is deliberately NOT
     * re-checked here — that is `geo_mismatch`'s job, and it is a fraud
     * decision with a score and an event rather than a flat refusal.
     */
    public function isBlockedForPublisher(WallContext $context, Offer $offer): bool
    {
        $blacklist = $this->blacklist($context->app);
        $country = $context->country;

        if ($country !== null && in_array($country, (array) ($context->app->blocked_countries ?? []), true)) {
            return true;
        }

        // `device` is a DeviceType, and the blacklist stores its string value —
        // comparing the enum itself silently matches nothing.
        return in_array((string) $offer->id, $blacklist[BlacklistType::Offer->value], true)
            || in_array((int) $offer->network_id, AppNetworks::resolveIds($blacklist[BlacklistType::Network->value]), true)
            || ($country !== null && in_array($country, $blacklist[BlacklistType::Country->value], true))
            || in_array($context->device->value, $blacklist[BlacklistType::Device->value], true);
    }

    /**
     * @return array<string, list<string>>
     */
    private function blacklist(App $app): array
    {
        $rows = Blacklist::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $app->publisher_id)
            // A null app_id is a publisher-wide rule; it applies here too.
            ->where(fn (Builder $query) => $query->whereNull('app_id')->orWhere('app_id', $app->id))
            ->get(['type', 'value']);

        $grouped = [];

        foreach (BlacklistType::cases() as $type) {
            $grouped[$type->value] = $rows
                ->where('type', $type)
                ->pluck('value')
                ->map(fn ($value): string => (string) $value)
                ->values()
                ->all();
        }

        return $grouped;
    }

    /**
     * The only per-user query in the path, and the reason it is 60 seconds and
     * not 300: a user who just converted expects the offer to be gone when they
     * come back.
     *
     * @return list<int>
     */
    private function completedOfferIds(WallContext $context): array
    {
        /** @var list<int> */
        return Cache::remember(
            sprintf('wall:completed:%d:%s', $context->app->id, sha1($context->endUserId)),
            self::COMPLETED_TTL,
            fn (): array => Conversion::query()
                ->withoutGlobalScopes()
                ->where('app_id', $context->app->id)
                ->where('end_user_id', $context->endUserId)
                ->whereIn('status', [ConversionStatus::Pending, ConversionStatus::Approved])
                ->pluck('offer_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        );
    }

    /** An offer we cannot place against the floor is not served — the same fail-closed rule as country. */
    private function meetsOsFloor(?string $floor, ?string $detected): bool
    {
        if (blank($floor)) {
            return true;
        }

        return $detected !== null && version_compare($detected, (string) $floor, '>=');
    }

    /**
     * The user sees currency, never USD: `$0.50` at 70% rev-share and 1000
     * coins per dollar is 350 coins.
     *
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    private function display(array $offer, WallContext $context): array
    {
        $reward = $this->convert($context, (float) $offer['payout']);

        return $offer + [
            'reward' => $reward,
            'reward_label' => self::rewardLabel($reward),
        ];
    }

    /**
     * One USD figure in the publisher's own currency.
     *
     * Public because the detail view converts figures the cached wall payload
     * never carried — each step of a multi-reward offer — and doing that
     * arithmetic a second time somewhere else is how a wall ends up quoting a
     * total its own steps do not add up to.
     */
    public function convert(WallContext $context, float $payoutUsd): float
    {
        return (float) Money::currency(
            Money::share(Money::format($payoutUsd), Money::format($context->app->publisher->rev_share, 2)),
            $context->app->currency_rate,
        );
    }

    /**
     * Real catalogues carry $2,000 payouts, and at 1,000 coins per dollar that
     * is a seven-digit reward. Printed in full it takes over the card and
     * flattens the reward column the user is scanning — and a tenth of a coin
     * out of 1.4 million is not information. So the label degrades with
     * magnitude: exact under 1,000, whole numbers above it, millions
     * abbreviated. `reward` keeps the exact figure for anything that needs it.
     *
     * Public because the header's "available on this wall" figure is a sum of
     * the same rewards and has to degrade the same way — a second formatter
     * would drift, and the two numbers sit six lines apart on screen.
     */
    public static function rewardLabel(float $reward): string
    {
        if ($reward >= 1_000_000) {
            return rtrim(rtrim(number_format($reward / 1_000_000, 2, '.', ''), '0'), '.').'M';
        }

        if ($reward >= 1_000) {
            return number_format($reward, 0, '.', ',');
        }

        return rtrim(rtrim(number_format($reward, 2, '.', ','), '0'), '.');
    }

    /**
     * The offer as a task list. Shared by the HTML detail view and the SDK API.
     *
     * An offer with no stored goals still gets one row rather than an empty
     * panel: it has exactly one thing to do, and saying so is information.
     * Rewards are converted per step from USD, so the steps add up to the
     * headline figure instead of being a second, differently-rounded answer.
     *
     * @return list<array{name: string, reward: string}>
     */
    public function steps(WallContext $context, Offer $offer): array
    {
        $goals = array_values(array_filter((array) ($offer->goals ?? []), 'is_array'));

        if ($goals === []) {
            return [[
                'name' => __('wall.offer.single_step'),
                'reward' => self::rewardLabel($this->convert($context, (float) $offer->payout)),
            ]];
        }

        return array_map(fn (array $goal): array => [
            'name' => (string) ($goal['name'] ?? ''),
            'reward' => self::rewardLabel($this->convert($context, (float) ($goal['payout'] ?? 0))),
        ], $goals);
    }

    /**
     * Test mode's `$0.01` offer, pinned above everything (docs/03 §7). The row
     * itself is platform inventory, named by `settings.test_offer_id`, so a
     * click on it runs the real pipeline instead of a simulation.
     *
     * @return array<string, mixed>|null
     */
    private function testOffer(WallContext $context): ?array
    {
        $id = Setting::get('test_offer_id');

        if (! is_numeric($id)) {
            return null;
        }

        $offer = Offer::query()->find((int) $id);

        return $offer === null ? null : $this->display([
            'id' => $offer->id,
            'name' => $offer->name,
            'instructions' => Plain::text($offer->instructions),
            'payout' => (float) $offer->payout,
            'os_min_version' => null,
            'is_multi_reward' => true,
            'goal_count' => count(array_filter((array) ($offer->goals ?? []), 'is_array')),
            'is_featured' => true,
            'categories' => [],
            // Built by hand rather than through build(), so every key the card
            // partial reads has to be listed here too. Test inventory is
            // device-agnostic and has no popularity worth quoting — it is one
            // row an integrator clicks, not something people are doing.
            'devices' => [],
            'people' => 0,
            'has_icon' => filled($offer->icon_url),
            'is_test' => true,
        ], $context);
    }

    /** Whether a click on this offer is the test-mode click (docs/03 §7). */
    public static function isTestOffer(Offer $offer): bool
    {
        $id = Setting::get('test_offer_id');

        return is_numeric($id) && (int) $id === (int) $offer->id;
    }
}
