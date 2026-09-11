<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the network last told us, kept apart from what we decided.
 *
 * `conversions.status` stays the only money truth: a fraud rejection of ours
 * outranks an upstream approval, and nothing reads these two columns to decide
 * whether to pay. They exist because the reconciler now acts on a *sequence* of
 * upstream states — pending, then approved, then possibly rejected — and
 * without them an admin looking at a pending row cannot tell "the network has
 * not decided" from "the network approved it and our own rules held it back".
 *
 * 16 characters is the internal vocabulary (`pending|approved|rejected|
 * reversed`); every driver's wording is normalised to it on the way in, so the
 * column never carries two names for one state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->string('upstream_status', 16)->nullable()->after('rejected_reason');
            $table->timestamp('upstream_updated_at')->nullable()->after('upstream_status');
        });
    }

    public function down(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->dropColumn(['upstream_status', 'upstream_updated_at']);
        });
    }
};
