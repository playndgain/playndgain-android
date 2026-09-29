<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable memory for a catalogue that can only be seen one country and one
 * device at a time (AyetStudiosDriver's probe matrix).
 *
 * Two tables because the two halves have very different sizes. A cell is
 * one (country, platform) answer and only needs the ids it returned; the
 * offer body is the same in every cell it appears in, so it is stored once.
 * A table rather than the cache because a deploy that clears the cache
 * would otherwise forget every country not probed on the next run, and the
 * sync job deactivates whatever a run does not yield.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_probe_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->char('country', 2);
            $table->string('platform', 16);
            $table->json('offer_ids');
            $table->timestamp('probed_at');

            $table->unique(['network_id', 'country', 'platform']);
            $table->index(['network_id', 'probed_at']);
        });

        Schema::create('network_probe_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->json('payload');
            $table->timestamp('seen_at');

            $table->unique(['network_id', 'external_id']);
            $table->index(['network_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_probe_offers');
        Schema::dropIfExists('network_probe_cells');
    }
};
