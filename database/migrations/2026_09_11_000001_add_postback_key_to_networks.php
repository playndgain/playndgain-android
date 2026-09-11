<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Callback identity moves from the driver key to a per-row key.
 *
 * `/postback/{driver}` assumed one row per driver, which a tracking platform
 * breaks: several partners each run their own tenant on Swaarm or Affise, and
 * each tenant needs its own callback URL and its own secret. The route segment
 * therefore becomes a value the row owns rather than the name of its class.
 *
 * Every existing row is backfilled with `postback_key = driver`, so every URL
 * already pasted into a partner dashboard keeps resolving to the same network.
 *
 * The unique index on `driver` is what enforced one-row-per-driver. It becomes
 * `unique(driver, account_scope)` with an empty scope for single-account
 * connectors: the database still refuses a second `ogads` row, and a platform
 * connector scopes each account by its own key. Every `->first()` and
 * `firstOrNew(['driver' => …])` caller stays deterministic for the direct
 * drivers, which are the only ones those callers know about.
 */
return new class extends Migration
{
    /** Matches the column width; a hand-typed driver longer than this is truncated, not rejected. */
    private const KEY_LENGTH = 64;

    public function up(): void
    {
        // MySQL will not drop and create an index on `driver` in one ALTER.
        Schema::table('networks', function (Blueprint $table) {
            $table->dropUnique(['driver']);
        });

        Schema::table('networks', function (Blueprint $table) {
            $table->index('driver');
        });

        // Nullable first: a NOT NULL unique column cannot be added to a
        // populated table in a single pass.
        Schema::table('networks', function (Blueprint $table) {
            $table->string('postback_key', self::KEY_LENGTH)->nullable()->after('driver');
            $table->string('account_scope', self::KEY_LENGTH)->default('')->after('postback_key');
        });

        DB::table('networks')
            ->select('id', 'driver')
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('networks')
                        ->where('id', $row->id)
                        ->update(['postback_key' => Str::substr((string) $row->driver, 0, self::KEY_LENGTH)]);
                }
            });

        Schema::table('networks', function (Blueprint $table) {
            $table->string('postback_key', self::KEY_LENGTH)->nullable(false)->change();
            $table->unique('postback_key');
            $table->unique(['driver', 'account_scope']);
        });

        // The raw route segment, kept apart from `driver` so the replay
        // command's `--driver` filter and the log's grouping keep meaning the
        // class. Nullable because rows written before this migration have no
        // segment to attribute, and the column is the segment, not a guess.
        Schema::table('incoming_postbacks', function (Blueprint $table) {
            $table->string('postback_key', self::KEY_LENGTH)->nullable()->after('driver')->index();
        });
    }

    /**
     * Reversing restores the one-row-per-driver guarantee, so it fails loudly
     * if a platform account was created in the meantime. That is the intended
     * guard: silently collapsing two tenants onto one row would point both
     * partners' callbacks at whichever survived.
     */
    public function down(): void
    {
        Schema::table('incoming_postbacks', function (Blueprint $table) {
            $table->dropIndex(['postback_key']);
            $table->dropColumn('postback_key');
        });

        Schema::table('networks', function (Blueprint $table) {
            $table->dropUnique(['driver', 'account_scope']);
            $table->dropUnique(['postback_key']);
        });

        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn(['postback_key', 'account_scope']);
        });

        Schema::table('networks', function (Blueprint $table) {
            $table->dropIndex(['driver']);
        });

        Schema::table('networks', function (Blueprint $table) {
            $table->unique('driver');
        });
    }
};
