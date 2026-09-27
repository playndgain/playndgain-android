<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audience report's GEO dimension. Nullable because rows written before
 * this column existed have no country and are reported as unknown, never
 * inferred. The index serves the report's one predicate:
 * `kind = 'wall' AND created_at` in a range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_sessions', function (Blueprint $table) {
            $table->char('country', 2)->nullable()->after('end_user_id');
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('identity_sessions', function (Blueprint $table) {
            $table->dropIndex(['kind', 'created_at']);
            $table->dropColumn('country');
        });
    }
};
