<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How we talk to the partner: catalogue API, their hosted wall, or both.
     *
     * `hosts_wall` stays — it is what the admin table already filters on — and
     * is kept in step with this column on save. A second stored answer for
     * "does the driver have a catalogue" is still refused; this one is the
     * admin saying we will not call it, which is what lets an embed-only row
     * skip the API keys the driver otherwise requires.
     */
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->string('integration', 16)->default('api')->after('hosts_wall');
        });

        // A wall already marked on the row is "both" unless the driver has no
        // catalogue at all — those were embed-only before this column existed.
        DB::table('networks')->where('hosts_wall', true)->update(['integration' => 'both']);
        DB::table('networks')->where('driver', 'mychips')->update(['integration' => 'embed']);
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('integration');
        });
    }
};
