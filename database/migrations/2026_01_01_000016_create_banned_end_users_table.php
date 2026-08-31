<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bans are scoped to the publisher who owns the traffic. End user IDs are
     * not globally unique — `user_1` on two different apps is two people.
     */
    public function up(): void
    {
        Schema::create('banned_end_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();
            $table->string('end_user_id', 191);
            $table->string('banned_by');
            $table->string('reason')->nullable();

            // Null = permanent. Auto-bans always carry an expiry.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['publisher_id', 'app_id', 'end_user_id']);
            $table->index('end_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banned_end_users');
    }
};
