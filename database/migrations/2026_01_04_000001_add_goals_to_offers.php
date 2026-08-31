<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The steps of a multi-reward offer, which every driver already parsed and
 * every driver then threw away.
 *
 * `is_multi_reward` was derived from `count($events) > 1` and the events
 * themselves were dropped on the floor, so the wall could say "pays more than
 * once" and nothing more — not what the steps are, not what each one is worth.
 * That is the single largest thing an end user wants to know before starting a
 * two-week game offer, and it was the one thing we could not tell them.
 *
 * Shape is `[{"name": "Reach level 50", "payout": 2.0}, ...]` in USD, exactly
 * like `offers.payout`: the wall converts to the publisher's currency at render
 * time, because rev-share and currency rate are per-publisher and a stored
 * conversion would be wrong for every other one.
 *
 * Nullable, and empty for most inventory. A single-step offer has no steps to
 * list — the offer *is* the step — and the detail view says so itself rather
 * than storing a synthetic goal per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->json('goals')->nullable()->after('is_multi_reward');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('goals');
        });
    }
};
