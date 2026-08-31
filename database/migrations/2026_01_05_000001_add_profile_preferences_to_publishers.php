<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two profile settings docs/08 §14 asks for and the schema never had.
 *
 * On `publishers` rather than `users`: every consumer already resolves a
 * publisher to get here — a postback failure reaches one through its app, a
 * withdrawal status change owns one directly — and admin accounts have no use
 * for either column.
 *
 * `timezone` nullable means "the platform default", not "unset". Storing
 * `config('app.timezone')` for everyone would freeze today's default into
 * every existing row and make a future change to it a data migration.
 *
 * `notification_preferences` is JSON and nullable for the same reason, with
 * null read as every notification on. A publisher who has never opened the
 * page has not opted out of anything, and a column of booleans would need a
 * migration per notification type we add.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('country');
            $table->json('notification_preferences')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'notification_preferences']);
        });
    }
};
