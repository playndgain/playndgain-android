<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Partners often will not publish firing IPs, so a new network now admits
     * any source until an admin turns the gate back on and fills the list.
     */
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->boolean('allow_any_postback_ip')->default(true)->change();
        });

        DB::table('networks')->update(['allow_any_postback_ip' => true]);
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->boolean('allow_any_postback_ip')->default(false)->change();
        });
    }
};
