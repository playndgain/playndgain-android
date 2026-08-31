<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_postbacks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('conversion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('kind');

            // Fully resolved URL, secrets redacted before storage.
            $table->text('url');

            $table->string('method');
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedSmallInteger('http_status')->nullable();

            // Truncated to 2 KB on write — publishers return HTML error pages.
            $table->text('response_body')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('conversion_id');
            $table->index(['app_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_postbacks');
    }
};
