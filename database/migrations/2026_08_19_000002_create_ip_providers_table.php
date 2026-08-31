<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A table rather than `settings` rows: each provider carries per-row live
     * state — breaker, last error, when — and a key/value store models that by
     * inventing composite keys nobody can query.
     *
     * Daily request counters are deliberately absent. They live in Redis: they
     * are written on every escalated lookup, and a table write on the click
     * path is the exact cost this whole design exists to avoid.
     */
    public function up(): void
    {
        Schema::create('ip_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('priority')->default(10);
            $table->text('api_key')->nullable();
            $table->unsignedSmallInteger('timeout_ms')->default(600);
            $table->unsignedInteger('daily_cap')->nullable();
            $table->unsignedInteger('ttl_seconds')->default(86400);
            $table->boolean('fail_open')->default(true);
            $table->unsignedTinyInteger('circuit_failures')->default(5);
            $table->unsignedTinyInteger('circuit_cooldown_minutes')->default(10);
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->timestamp('circuit_opened_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_providers');
    }
};
