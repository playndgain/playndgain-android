<?php

use App\Models\Network;
use Illuminate\Database\Migrations\Migration;

/**
 * The ayeT driver moved from the Static API to the Offerwall API. The adslot
 * Static API key is no longer read by anything, and the Offerwall API prices
 * offers in the adslot's own currency, so the driver now needs the rate.
 *
 * The stale key is dropped so an encrypted secret nothing uses does not sit
 * in the row forever. The rate is filled with 1000 coins per USD, the figure
 * verified against the live adslot on 2026-09-29, and only when blank: an
 * admin who has already typed a rate keeps it. Without this the first sync
 * after deploy would fail on a missing credential until someone typed it.
 *
 * `offers_count` is zeroed because it still holds the Static API catalogue
 * size. The first probe runs see only part of the world, and the sync job's
 * collapse guard would read that as a catalogue drop and refuse every run
 * until an admin reset it by hand.
 *
 * Through the model because `networks.credentials` is `encrypted:array`, the
 * same reason the Monlix rename goes through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Network::query()->where('driver', 'ayetstudios')->get()->each(function (Network $network): void {
            $credentials = is_array($network->credentials) ? $network->credentials : [];
            unset($credentials['api_key']);

            if (blank($credentials['coins_per_usd'] ?? null)) {
                $credentials['coins_per_usd'] = '1000';
            }

            $network->update(['credentials' => $credentials, 'offers_count' => 0]);
        });
    }

    /** The Static API key is gone for good; there is nothing to restore it from. */
    public function down(): void {}
};
