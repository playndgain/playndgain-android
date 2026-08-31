<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->string('driver')->unique();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->index();

            // encrypted:array cast — never logged, never in an exception trace.
            $table->text('credentials')->nullable();

            $table->json('postback_ips')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->decimal('margin_override', 5, 2)->nullable();
            $table->unsignedSmallInteger('sync_interval_minutes')->default(60);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->unsignedInteger('offers_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('networks');
    }
};
