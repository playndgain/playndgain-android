<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pre-aggregated reporting so dashboards never scan `offer_clicks`.
     *
     * The aggregation query filters `is_test = false` on both source tables —
     * test traffic reaching a revenue chart makes every number in both panels
     * wrong by however much the publisher tested.
     */
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('network_id')->nullable()->constrained()->nullOnDelete();
            $table->char('country', 2)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->unsignedInteger('reversals')->default(0);

            // What networks paid us, and what we owe publishers.
            $table->decimal('revenue', 14, 6)->default(0);
            $table->decimal('payout', 14, 6)->default(0);

            $table->timestamps();

            $table->unique(
                ['date', 'publisher_id', 'app_id', 'network_id', 'country'],
                'daily_stats_dimensions_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
