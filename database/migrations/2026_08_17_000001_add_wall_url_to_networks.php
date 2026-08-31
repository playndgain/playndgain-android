<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            // The partner's own hosted wall, as a macro template. Nullable
            // because most networks are API integrations with no wall of their
            // own to frame — see docs/03-offerwall.md §2.
            $table->text('wall_url')->nullable()->after('website');

            // Set when the partner's frame-ancestors refuses us. The wall then
            // renders a button instead of a grey box nobody can use.
            $table->boolean('wall_new_tab')->default(false)->after('wall_url');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn(['wall_url', 'wall_new_tab']);
        });
    }
};
