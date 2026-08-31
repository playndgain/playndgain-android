<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('end_user_id', 191);
            $table->boolean('granted');
            $table->json('purposes')->nullable();
            $table->string('ip', 45);
            $table->char('country', 2)->nullable();
            $table->string('user_agent_hash', 64);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Latest state only. Historical rows are a v2 concern.
            $table->unique(['app_id', 'end_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
