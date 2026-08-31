<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `revlum`, `wannads`, `youmi` and `dtny` lost their driver classes and their
 * seeder entries, but an install seeded before that still owns a `networks` row
 * for each. `NetworkSeeder` only ever `updateOrCreate`s, so nothing takes them
 * away.
 *
 * Left active, such a row is a trap. `SyncNetworkOffersJob::handle()` throws
 * `UnknownDriverException` at `$manager->make()`, which happens before the try
 * block and before any status write — no `network_syncs` row, `last_sync_status`
 * never set to `failed`, the consecutive-failure alert never fires. The failure
 * exists only in `failed_jobs`, where nobody is looking.
 *
 * Disabled, not deleted, and the choice is not close. `offers`, `offer_clicks`
 * and `conversions` all carry `foreignId('network_id')->constrained()
 * ->cascadeOnDelete()`, so deleting one of these rows would take every offer we
 * ever pulled from it, every click a publisher sent to it, and every conversion
 * we credited and paid out on — while `incoming_postbacks` uses `nullOnDelete()`
 * and would keep the raw log with nothing to attribute it to. This platform
 * never hard-deletes what money references; the sync job itself deactivates
 * vanished offers rather than removing them, for exactly this reason. A disabled
 * row costs one line in an admin list. A deleted one costs the audit trail
 * behind real payouts.
 *
 * Disabling is also sufficient on both paths. `networks:sync` selects
 * `status = active`, so a disabled row is never queued and can never reach the
 * throw. `PostbackController` rejects a non-active network at its own gate with
 * a 403 and an `incoming_postbacks` row marked rejected — the recorded rejection
 * the old StubDriver used to give.
 *
 * `last_sync_status` is deliberately left alone: no sync failed here, and
 * claiming one did would put a false red badge in the admin table. The
 * breadcrumb goes in `last_sync_error`, which the resource surfaces on the row
 * and in the edit form, so an admin who finds a disabled network can read why.
 */
return new class extends Migration
{
    private const REMOVED_DRIVERS = ['revlum', 'wannads', 'youmi', 'dtny'];

    private const REASON = 'Driver removed from the platform. The row is kept because its offers, clicks and conversions reference it.';

    public function up(): void
    {
        DB::table('networks')
            ->whereIn('driver', self::REMOVED_DRIVERS)
            // Literal rather than NetworkStatus::Disabled — a migration is a
            // snapshot, and must keep running if the enum is ever renamed.
            ->where('status', '!=', 'disabled')
            ->update([
                'status' => 'disabled',
                'last_sync_error' => self::REASON,
                'updated_at' => now(),
            ]);
    }

    /**
     * Deliberately a no-op, and honest about it. Rolling this migration back
     * does not bring `RevlumDriver` and friends back into `app/Networks/Drivers`
     * — they are gone from every version of the tree from this branch forward.
     * Re-activating the rows would restore the silent `failed_jobs`-only failure
     * this migration exists to end, and there is nothing recorded anywhere that
     * says which of the four were active to begin with.
     */
    public function down(): void {}
};
