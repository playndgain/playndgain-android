<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * External ids an admin has chosen to import even when the driver would
     * skip them — typically targeting the wall cannot honour.
     */
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->json('unskipped_external_ids')->nullable()->after('last_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('unskipped_external_ids');
        });
    }
};
