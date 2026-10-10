<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a click came from (the native SDKs), the build evidence the SDK sends
 * for the emulator rule, and up to five publisher sub-ids echoed back as
 * {sub1}..{sub5} on the postback.
 *
 * All nullable: the iframe wall sends none of them, and a NOT NULL column on
 * the busiest insert in the system would turn a missing parameter into a 500.
 * No index: nothing filters on these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_clicks', function (Blueprint $table) {
            $table->string('sdk_platform', 8)->nullable()->after('browser');
            $table->string('sdk_version', 16)->nullable()->after('sdk_platform');
            $table->string('device_model', 64)->nullable()->after('sdk_version');
            $table->string('device_hint', 256)->nullable()->after('device_model');

            foreach (range(1, 5) as $n) {
                $table->string("sub{$n}", 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('offer_clicks', function (Blueprint $table) {
            $table->dropColumn(['sdk_platform', 'sdk_version', 'device_model', 'device_hint', 'sub1', 'sub2', 'sub3', 'sub4', 'sub5']);
        });
    }
};
