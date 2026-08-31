<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_clicks', function (Blueprint $table) {
            $table->bigIncrements('id');

            // UUIDv4. The join key for the whole conversion path.
            $table->uuid('click_id')->unique();

            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->string('end_user_id', 191);
            $table->string('ip', 45);
            $table->char('country', 2)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_type')->nullable();
            $table->string('os')->nullable();
            $table->string('browser')->nullable();
            $table->text('referrer')->nullable();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->boolean('blocked')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['app_id', 'created_at']);
            $table->index(['end_user_id', 'offer_id']);
            $table->index(['publisher_id', 'created_at']);
            $table->index('country');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_clicks');
    }
};
