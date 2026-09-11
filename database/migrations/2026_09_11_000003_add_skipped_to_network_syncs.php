<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a catalogue run refused, and why.
 *
 * Driver-side skips were silent: a partner catalogue of four thousand that
 * yields nine hundred is indistinguishable from a catalogue of nine hundred,
 * and "where did the rest go" had no answer anywhere in the application. The
 * platform connectors make that acute — they drop every offer priced along a
 * dimension the wall cannot filter on, which is a design decision an operator
 * has to be able to see rather than a bug they have to infer.
 *
 * `skip_reasons` holds `{reason: {count: int, samples: [external ids]}}`, capped
 * at five ids per reason: enough to look one up in the partner's dashboard,
 * small enough that this stays a summary rather than a second copy of the feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_syncs', function (Blueprint $table) {
            $table->unsignedInteger('offers_skipped')->default(0)->after('offers_deactivated');
            $table->json('skip_reasons')->nullable()->after('offers_skipped');
        });
    }

    public function down(): void
    {
        Schema::table('network_syncs', function (Blueprint $table) {
            $table->dropColumn(['offers_skipped', 'skip_reasons']);
        });
    }
};
