<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money columns are decimal(14,6) throughout. The spec asks for UNSIGNED on
     * the columns that cannot go negative, but MySQL deprecated UNSIGNED on
     * DECIMAL in 8.0.17 and production targets MySQL 9.7 — so the constraint is
     * expressed in the domain layer instead of in a column type that is on its
     * way out. `balance` is signed in every reading of the spec anyway.
     */
    public function up(): void
    {
        Schema::create('publishers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pub_id')->unique();
            $table->string('company_name')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('status')->index();
            $table->decimal('rev_share', 5, 2);
            $table->unsignedSmallInteger('hold_days')->nullable();
            $table->decimal('min_payout', 10, 2)->default(20);

            // Cached, derived from ledger_entries. Signed — can go negative.
            $table->decimal('balance', 14, 6)->default(0);
            $table->decimal('lifetime_earnings', 14, 6)->default(0);

            $table->text('traffic_source')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishers');
    }
};
