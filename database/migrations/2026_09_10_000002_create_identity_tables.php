<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();
            $table->string('end_user_id', 191)->nullable();
            $table->string('ip', 45);
            $table->char('ua_hash', 64);
            $table->char('ds_hash', 64)->nullable();
            $table->string('session_id', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['kind', 'user_id', 'created_at']);
            $table->index(['publisher_id', 'ip', 'end_user_id']);
            $table->index(['publisher_id', 'ds_hash', 'end_user_id']);
            $table->index('session_id');
        });

        Schema::create('account_matches', function (Blueprint $table) {
            $table->id();
            $table->char('match_key', 40)->unique();
            $table->string('scope', 16);
            $table->string('signal', 16);
            $table->string('signal_value', 191)->nullable();
            $table->foreignId('publisher_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('left_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('right_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('left_end_user_id', 191)->nullable();
            $table->string('right_end_user_id', 191)->nullable();
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->index(['scope', 'last_seen_at']);
            $table->index(['publisher_id', 'last_seen_at']);
            $table->index(['left_user_id', 'right_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_matches');
        Schema::dropIfExists('identity_sessions');
    }
};
