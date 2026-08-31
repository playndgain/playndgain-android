<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw log of everything a network sent us, written BEFORE parsing. When a
     * network claims they sent a conversion, this table settles it.
     */
    public function up(): void
    {
        Schema::create('incoming_postbacks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('network_id')->nullable()->constrained()->nullOnDelete();
            $table->string('driver');
            $table->string('method');
            $table->json('query')->nullable();
            $table->json('body')->nullable();

            // Sensitive headers stripped before storage.
            $table->json('headers')->nullable();

            $table->string('ip', 45);
            $table->boolean('signature_valid')->nullable();
            $table->boolean('ip_allowed')->nullable();
            $table->string('result');
            $table->string('reject_reason')->nullable();
            $table->foreignId('conversion_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['driver', 'created_at']);
            $table->index('result');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_postbacks');
    }
};
