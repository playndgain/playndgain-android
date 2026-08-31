<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();

            // Null = applies to all of that publisher's apps.
            $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();

            $table->string('type');
            $table->string('value');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['publisher_id', 'app_id', 'type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklists');
    }
};
