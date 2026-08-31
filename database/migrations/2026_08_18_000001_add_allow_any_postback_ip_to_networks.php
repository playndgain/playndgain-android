<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Escape hatch for partners that will not publish firing IPs.
     *
     * Empty `postback_ips` still rejects. This flag is the only way to admit
     * every source, and it has to be an admin's declaration — deriving it
     * from an empty list is the composition that used to make an unsigned
     * postback an unauthenticated money endpoint.
     */
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->boolean('allow_any_postback_ip')->default(false)->after('postback_ips');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('allow_any_postback_ip');
        });
    }
};
