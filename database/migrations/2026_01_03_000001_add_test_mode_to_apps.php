<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The toggle docs/03 §7 and docs/08 §4 both describe and the schema never had.
 *
 * `WallGate::testMode()` has been reading it through the attribute bag since
 * before the column existed, which meant the feature was permanently off: the
 * `country` query parameter was ignored, so a publisher could not preview a geo
 * and the wall showed only worldwide inventory on any box without GeoLite2.
 *
 * Default false is the load-bearing part. `country` is a targeting override,
 * and an override that applies to a live app is a way to be served offers the
 * targeting refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->boolean('test_mode')->default(false)->after('anonymize_ip');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('test_mode');
        });
    }
};
