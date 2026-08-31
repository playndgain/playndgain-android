<?php

namespace App\Networks;

use App\Models\App as PublisherApp;
use App\Models\Network;
use Illuminate\Support\Facades\Cache;

/**
 * Tagged flushes, per docs/02 §5. Wall cache keys are scoped by `app_id`, so
 * one publisher's blacklist edit never clears — or leaks into — another's wall.
 *
 * Requires a taggable cache store; Redis is a hard dependency of the platform
 * (docs/11 §1), not a preference.
 */
final class OfferCacheFlusher
{
    public static function flushForNetwork(Network $network): void
    {
        Cache::tags("network:{$network->id}")->flush();

        // OfferFeed tags every wall payload with the current active set. A
        // disable/enable that only flushed this network's tag would miss walls
        // written before the network joined that set — they are namespaced
        // under the old tags. Dropping the set forces the next read to rebuild.
        Cache::forget('wall:active-networks');
    }

    public static function flushForApp(PublisherApp $app): void
    {
        Cache::tags("app:{$app->id}")->flush();
    }
}
