<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a partner brings a wall of its own, as an admin's declaration.
     *
     * The other half of what a partner brings — whether it has a catalogue we
     * sync — is not stored, because `NetworkDriver::syncsCatalogue()` already
     * answers it and the sync job already reads it. A column beside it would be
     * a second answer, free to drift from the first.
     *
     * This one has to be stored, and the reason is narrow: it reveals the wall
     * URL field. Deriving it from `wall_url` instead would mean the field only
     * appears once the value exists, so nobody could ever type it.
     */
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->boolean('hosts_wall')->default(false)->after('wall_new_tab');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('hosts_wall');
        });
    }
};
