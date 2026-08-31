<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real catalogues carry signed CDN icon links and store pages with a query
 * string longer than the whole 255-byte column, so a single long URL failed the
 * insert and took the entire sync run down with it.
 *
 * A URL cannot be truncated to fit — a shortened one is a broken image, not a
 * smaller one — so the columns grow instead. `tracking_url` was already `text`
 * for the same reason; these two were the ones left behind. Neither is indexed
 * or searched, so nothing is lost by the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->text('icon_url')->nullable()->change();
            $table->text('preview_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('icon_url')->nullable()->change();
            $table->string('preview_url')->nullable()->change();
        });
    }
};
