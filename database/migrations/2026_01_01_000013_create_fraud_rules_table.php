<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rules live in the database, not in code — thresholds change weekly in
     * response to what fraud farms are doing, and a threshold change must not
     * require a deploy.
     */
    public function up(): void
    {
        Schema::create('fraud_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description');
            $table->string('stage')->index();
            $table->boolean('enabled')->default(true);
            $table->string('action');
            $table->unsignedTinyInteger('score_weight')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_rules');
    }
};
