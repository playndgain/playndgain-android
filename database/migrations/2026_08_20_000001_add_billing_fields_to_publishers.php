<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address block on a credit note. company_name and country already exist;
 * these four are what the ayeT-style invoice still needs and the schema never
 * had. Nullable — a payout can print without them, it just looks unfinished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->string('billing_address')->nullable()->after('country');
            $table->string('billing_city')->nullable()->after('billing_address');
            $table->string('billing_postal_code', 32)->nullable()->after('billing_city');
            $table->string('vat_number', 64)->nullable()->after('billing_postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->dropColumn([
                'billing_address',
                'billing_city',
                'billing_postal_code',
                'vat_number',
            ]);
        });
    }
};
