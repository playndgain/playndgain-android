<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('rule_key');
            $table->string('stage');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('end_user_id', 191)->nullable();
            $table->string('action_taken');

            // The inputs the rule saw. A block we cannot explain is a block we
            // will eventually turn off.
            $table->json('context')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('rule_key');
            $table->index(['publisher_id', 'created_at']);
            $table->index(['end_user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_events');
    }
};
