<?php

namespace App\Support;

use App\Enums\BlacklistType;
use App\Enums\NetworkStatus;
use App\Models\App;
use App\Models\Blacklist;
use App\Models\Network;
use App\Networks\OfferCacheFlusher;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which suppliers an app is allowed to serve.
 *
 * The wall, publisher inventory and the admin app form all read through here,
 * so a toggle on the placement and a Networks blacklist row cannot disagree.
 * Publishers never see these names — that list is admin-only. Stored values
 * are network ids; older rows and the factory still write the driver, and both
 * resolve to the same id list.
 */
final class AppNetworks
{
    /**
     * Active networks a placement can turn on or off, name keyed by id.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return Network::query()
            ->where('status', NetworkStatus::Active)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name])
            ->all();
    }

    /**
     * Ids currently on for this app. No record yet means every active network
     * — a new placement starts serving the full catalogue.
     *
     * @return list<string>
     */
    public static function enabledIds(?App $app): array
    {
        $active = array_keys(self::options());

        if ($app === null) {
            return $active;
        }

        $blocked = array_map(strval(...), self::blockedIds($app));

        return array_values(array_filter(
            $active,
            fn (string $id): bool => ! in_array($id, $blocked, true),
        ));
    }

    /**
     * Networks this placement must not serve — app-scoped rows plus publisher-
     * wide ones. Empty means every active network is allowed.
     *
     * @return list<int>
     */
    public static function blockedIds(App $app): array
    {
        return self::resolveIds(
            Blacklist::query()
                ->withoutGlobalScopes()
                ->where('publisher_id', $app->publisher_id)
                ->where('type', BlacklistType::Network)
                ->where(fn (Builder $query) => $query->whereNull('app_id')->orWhere('app_id', $app->id))
                ->pluck('value')
                ->all(),
        );
    }

    /**
     * Blocks that apply to every app the publisher owns. The placement toggle
     * cannot lift these — that is what Blacklists → Networks (all apps) is for.
     *
     * @return list<int>
     */
    public static function publisherWideBlockedIds(int $publisherId): array
    {
        return self::resolveIds(
            Blacklist::query()
                ->withoutGlobalScopes()
                ->where('publisher_id', $publisherId)
                ->where('type', BlacklistType::Network)
                ->whereNull('app_id')
                ->pluck('value')
                ->all(),
        );
    }

    /**
     * Accept network ids or drivers so a form save and a factory row hide the
     * same inventory.
     *
     * @param  iterable<int|string>  $values
     * @return list<int>
     */
    public static function resolveIds(iterable $values): array
    {
        $ids = [];
        $drivers = [];

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            if (ctype_digit($value)) {
                $ids[] = (int) $value;

                continue;
            }

            $drivers[] = $value;
        }

        if ($drivers !== []) {
            $ids = [
                ...$ids,
                ...Network::query()->whereIn('driver', $drivers)->pluck('id')->all(),
            ];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Persist the form's checked ids as app-scoped network blacklists. Null
     * means the field was not submitted — leave the rows alone so an unrelated
     * save cannot empty the wall.
     *
     * @param  list<int|string>|null  $enabledIds
     */
    public static function sync(App $app, ?array $enabledIds): void
    {
        if ($enabledIds === null) {
            return;
        }

        $enabled = array_map(intval(...), $enabledIds);
        $publisherWide = self::publisherWideBlockedIds((int) $app->publisher_id);

        foreach (array_keys(self::options()) as $id) {
            $id = (int) $id;

            if (in_array($id, $publisherWide, true)) {
                continue;
            }

            $blocked = Blacklist::query()
                ->withoutGlobalScopes()
                ->where('publisher_id', $app->publisher_id)
                ->where('app_id', $app->id)
                ->where('type', BlacklistType::Network)
                ->where('value', (string) $id)
                ->first();

            $wantOn = in_array($id, $enabled, true);

            if ($wantOn && $blocked !== null) {
                $blocked->delete();
            }

            if (! $wantOn && $blocked === null) {
                Blacklist::query()->create([
                    'publisher_id' => $app->publisher_id,
                    'app_id' => $app->id,
                    'type' => BlacklistType::Network,
                    'value' => (string) $id,
                    'reason' => 'Disabled on this app',
                ]);
            }
        }

        OfferCacheFlusher::flushForApp($app);
    }
}
