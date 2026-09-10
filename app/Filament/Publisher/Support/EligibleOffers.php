<?php

namespace App\Filament\Publisher\Support;

use App\Enums\AppStatus;
use App\Enums\BlacklistType;
use App\Enums\OfferStatus;
use App\Models\App;
use App\Models\Blacklist;
use App\Models\Offer;
use App\Support\AppNetworks;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a publisher's app can genuinely serve right now — docs/08 §5.
 *
 * Active offers on active networks, minus their own blacklists, minus anything
 * blocked globally, minus countries the app itself blocks. Both the inventory
 * page and the blacklist impact preview read through here, so the number the
 * preview promises is the number the page shows.
 */
final class EligibleOffers
{
    /**
     * Same predicates as WallGate::isServable(): approved account, active app.
     * Offers empty-state copy must call this rather than invent a second rule.
     */
    public static function catalogueOpen(?int $appId): bool
    {
        $publisher = CurrentPublisher::get();

        if ($publisher === null || ! $publisher->isApproved()) {
            return false;
        }

        if ($appId !== null) {
            $app = App::query()
                ->where('publisher_id', $publisher->id)
                ->whereKey($appId)
                ->first();

            return $app !== null && $app->isActive();
        }

        return App::query()
            ->where('publisher_id', $publisher->id)
            ->where('status', AppStatus::Active)
            ->exists();
    }

    /** @return Builder<Offer> */
    public static function query(?int $appId): Builder
    {
        if (! self::catalogueOpen($appId)) {
            return Offer::query()->whereRaw('1 = 0');
        }

        $publisherId = CurrentPublisher::id();

        $query = Offer::query()
            ->where('status', OfferStatus::Active)
            ->onActiveNetwork();

        $blacklists = Blacklist::query()
            ->where('publisher_id', $publisherId)
            // Entries with no app apply everywhere; entries with one apply only
            // to that placement.
            ->where(fn (Builder $scope) => $scope
                ->whereNull('app_id')
                ->when($appId !== null, fn (Builder $q) => $q->orWhere('app_id', $appId)))
            ->get()
            ->groupBy(fn (Blacklist $entry): string => $entry->type->value);

        foreach ($blacklists->get(BlacklistType::Offer->value, collect()) as $entry) {
            $query->whereKeyNot($entry->value);
        }

        $networkIds = AppNetworks::resolveIds(
            $blacklists->get(BlacklistType::Network->value, collect())
                ->map(fn (Blacklist $entry): string => (string) $entry->value)
                ->all(),
        );

        if ($networkIds !== []) {
            $query->whereNotIn('network_id', $networkIds);
        }

        foreach ($blacklists->get(BlacklistType::Device->value, collect()) as $entry) {
            // An offer targeting only a blocked device is unreachable. One that
            // also targets others stays, because it is still serveable.
            $query->whereNot(fn (Builder $q) => $q->whereJsonLength('devices', 1)
                ->whereJsonContains('devices', $entry->value));
        }

        $countries = $blacklists->get(BlacklistType::Country->value, collect())
            ->map(fn (Blacklist $entry): string => strtoupper($entry->value))
            ->all();

        $app = $appId === null ? null : App::query()->find($appId);
        $appBlocked = $app === null ? [] : ($app->blocked_countries ?? []);

        foreach ([...$countries, ...$appBlocked] as $country) {
            $query->whereNot(fn (Builder $q) => $q->whereJsonLength('countries', 1)
                ->whereJsonContains('countries', strtoupper($country)));
        }

        return $query;
    }

    /**
     * "This will remove 47 offers from your wall (est. −12% of eligible
     * inventory)" — computed before the entry is saved.
     */
    public static function impact(?int $appId, string $type, string $value): string
    {
        if ($type === '' || $value === '') {
            return '<span class="text-gray-400">Pick what to block to see the impact on your inventory.</span>';
        }

        $total = self::query($appId)->count();

        if ($total === 0) {
            return '<span class="text-gray-400">No eligible offers to measure against yet.</span>';
        }

        $removed = self::countRemoved($appId, $type, $value);
        $share = $removed / $total * 100;

        if ($removed === 0) {
            return '<span class="text-gray-400">Nothing currently eligible matches this. It will still apply to offers added later.</span>';
        }

        return sprintf(
            '<span class="%s">This removes <strong>%d</strong> of %d offers from your wall (about −%s%% of eligible inventory).</span>',
            $share >= 25 ? 'text-danger-400' : 'text-warning-400',
            $removed,
            $total,
            number_format($share, 1),
        );
    }

    /** Human-readable value for a saved entry — an offer ID means nothing on its own. */
    public static function label(Blacklist $record): string
    {
        return match ($record->type) {
            BlacklistType::Offer => (string) (Offer::query()->whereKey($record->value)->value('name') ?? "Offer #{$record->value}"),
            BlacklistType::Network => 'Supplier',
            default => strtoupper($record->value),
        };
    }

    private static function countRemoved(?int $appId, string $type, string $value): int
    {
        $query = self::query($appId);

        return match ($type) {
            BlacklistType::Offer->value => (clone $query)->whereKey($value)->count(),
            BlacklistType::Network->value => (clone $query)->whereIn('network_id', AppNetworks::resolveIds([$value]))->count(),
            BlacklistType::Device->value => (clone $query)
                ->whereJsonLength('devices', 1)
                ->whereJsonContains('devices', $value)
                ->count(),
            BlacklistType::Country->value => (clone $query)
                ->whereJsonLength('countries', 1)
                ->whereJsonContains('countries', strtoupper($value))
                ->count(),
            default => 0,
        };
    }
}
