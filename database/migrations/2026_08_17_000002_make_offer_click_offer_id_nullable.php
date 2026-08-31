<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A click on an embedded partner wall has no offer of ours to point at.
     *
     * Written against the raw column type rather than `foreignId()->change()`:
     * the column carries a foreign key, and MySQL keeps it across a MODIFY
     * COLUMN but not across a drop-and-recreate.
     */
    public function up(): void
    {
        Schema::table('offer_clicks', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversing means declaring every click has an offer again, and the
        // embed rows are the counter-example. Deleting them silently would
        // orphan conversions that were paid for. docs/11 §deploy is explicit
        // that a migration is rolled back only when it is reversible; this one
        // says so out loud rather than destroying evidence.
        if (DB::table('offer_clicks')->whereNull('offer_id')->exists()) {
            throw new RuntimeException(
                'offer_clicks holds rows with no offer (embedded partner walls). '
                .'Rolling back would require deleting them; do it deliberately, not as a side effect.'
            );
        }

        Schema::table('offer_clicks', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable(false)->change();
        });
    }
};
