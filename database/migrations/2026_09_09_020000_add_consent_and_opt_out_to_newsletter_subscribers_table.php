<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mailing list grows a consent record and a way off it.
 *
 * A list that stores an address with no opt-out and no note of when or where
 * consent was given is a list that cannot answer either question a data
 * request asks. `/legal/privacy` makes claims about both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            // Nullable rather than a boolean: the date is the evidence, and a
            // resubscribe clears it back to null rather than adding a column.
            $table->timestamp('unsubscribed_at')->nullable()->after('source');

            // Masked at the door by IpAnonymizer — network part only, which is
            // enough to show consent came from somewhere real and not enough to
            // identify a household. 45 chars fits a full IPv6 text form.
            $table->string('ip', 45)->nullable()->after('unsubscribed_at');
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            $table->dropColumn(['unsubscribed_at', 'ip']);
        });
    }
};
