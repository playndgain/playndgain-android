<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per catalogue run — docs/07 §4, the Sync Log.
     *
     * `networks` already carries `last_synced_at`, `last_sync_status` and
     * `last_sync_error`, but those are the *current* state and each run
     * overwrites the one before it. When a publisher says the wall went empty,
     * the answer is in the history: how many offers came back last night versus
     * this morning, how long the pull took, and what the run three ago failed
     * with. That question has no answer without this table.
     */
    public function up(): void
    {
        Schema::create('network_syncs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);

            // Seen is what the network returned; created and updated split it
            // into new inventory and refreshed inventory. Deactivated is the
            // number that matters after a complaint — it is the count of offers
            // that left a wall.
            $table->unsignedInteger('offers_seen')->default(0);
            $table->unsignedInteger('offers_created')->default(0);
            $table->unsignedInteger('offers_updated')->default(0);
            $table->unsignedInteger('offers_deactivated')->default(0);

            $table->string('status');
            $table->text('error')->nullable();

            // Written when the run ends, and the column `logs:prune` filters and
            // orders on — so it is indexed on its own as well as per network.
            $table->timestamp('created_at')->nullable();

            $table->index(['network_id', 'created_at']);
            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_syncs');
    }
};
