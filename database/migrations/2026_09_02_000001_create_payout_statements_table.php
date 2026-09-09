<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per publisher per calendar month — docs/06 §9. The statement is
 * what the month earned; the withdrawal it links to is the payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();

            // First day of the month, UTC. A date rather than a "Y-m" string so
            // it sorts, compares and formats without parsing.
            $table->date('period');

            $table->decimal('gross', 14, 6)->default(0);
            $table->decimal('chargebacks', 14, 6)->default(0);
            $table->decimal('adjustments', 14, 6)->default(0);
            $table->decimal('net', 14, 6);

            $table->string('status');
            $table->date('due_date');
            $table->foreignId('withdrawal_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('minimum_payout', 14, 2)->nullable();
            $table->string('note')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['publisher_id', 'period']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_statements');
    }
};
