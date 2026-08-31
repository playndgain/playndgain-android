<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('icon_url')->nullable();
            $table->string('preview_url')->nullable();
            $table->text('tracking_url');

            // What the network pays us, USD.
            $table->decimal('payout', 14, 6);

            $table->string('conversion_type');
            $table->json('countries')->nullable();
            $table->json('devices')->nullable();
            $table->string('os_min_version')->nullable();
            $table->json('categories')->nullable();
            $table->boolean('is_multi_reward')->default(false);
            $table->unsignedInteger('daily_cap')->nullable();
            $table->string('status');
            $table->boolean('is_featured')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['network_id', 'external_id']);
            $table->index('status');
            $table->index('payout');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
