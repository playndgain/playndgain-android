<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversions', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Our public transaction ID, sent to the publisher.
            $table->string('trans_id', 64)->unique();

            // The network's transaction ID. Unique WITH network_id — this pair
            // is the idempotency guarantee, enforced by the database because
            // two concurrent postback workers will race and only the database
            // can settle it.
            $table->string('network_txid', 191);

            $table->uuid('click_id')->nullable();
            $table->unsignedBigInteger('offer_click_id')->nullable();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->string('end_user_id', 191);

            // Snapshotted at conversion time — offers change names.
            $table->string('offer_name');

            $table->decimal('revenue', 14, 6);
            $table->decimal('payout', 14, 6);

            // Snapshotted — a later rev-share change must not rewrite history.
            $table->decimal('rev_share_applied', 5, 2);

            $table->decimal('currency_amount', 14, 4);
            $table->string('status');
            $table->boolean('is_test')->default(false);
            $table->string('rejected_reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->char('country', 2)->nullable();
            $table->timestamp('clears_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('offer_click_id')->references('id')->on('offer_clicks')->nullOnDelete();

            $table->unique(['network_id', 'network_txid']);
            $table->index(['publisher_id', 'created_at']);
            $table->index(['app_id', 'created_at']);
            $table->index('status');
            $table->index('clears_at');
            $table->index(['end_user_id', 'offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
    }
};
