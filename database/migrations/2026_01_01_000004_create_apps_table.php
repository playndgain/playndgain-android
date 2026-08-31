<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('app_id')->unique();
            $table->string('name');
            $table->string('url')->nullable();
            $table->string('platform');
            $table->string('status')->index();
            $table->string('icon_path')->nullable();

            // encrypted cast. Wall URL HMAC + outgoing postback signatures.
            $table->text('secret_key');

            $table->string('currency_name');
            $table->decimal('currency_rate', 14, 4);
            $table->text('postback_url')->nullable();
            $table->string('postback_method')->default('GET');
            $table->boolean('require_signed_wall')->default(false);
            $table->boolean('anonymize_ip')->default(false);
            $table->json('theme')->nullable();

            // Denormalised copy of the country blacklist, for wall filtering.
            $table->json('blocked_countries')->nullable();

            $table->unsignedInteger('daily_conversion_cap')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
