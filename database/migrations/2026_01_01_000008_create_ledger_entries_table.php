<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only. The only truth about money.
     *
     * No `updated_at` column, deliberately — there are no update statements and
     * no deletes. A mistake is corrected by an `adjustment` entry, never by
     * editing history. The model enforces the same rule at the ORM layer.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();
            $table->string('type');

            // Signed. Positive credits, negative debits.
            $table->decimal('amount', 14, 6);
            $table->decimal('balance_after', 14, 6);

            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['publisher_id', 'created_at']);
            $table->index('type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('available_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
