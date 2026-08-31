<?php

namespace App\Console\Commands;

use App\Enums\NetworkStatus;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\Network;
use Illuminate\Console\Command;

class SyncNetworksCommand extends Command
{
    protected $signature = 'networks:sync';

    protected $description = 'Queue a catalogue sync for every active network whose sync interval has elapsed.';

    /**
     * Runs every fifteen minutes; each network's own `sync_interval_minutes`
     * decides whether this tick is its turn. Twelve rows, so the due check is
     * cheaper to read in PHP than to express in SQL.
     */
    public function handle(): int
    {
        $due = Network::query()
            ->where('status', NetworkStatus::Active)
            ->get()
            ->filter(fn (Network $network) => $this->isDue($network));

        foreach ($due as $network) {
            SyncNetworkOffersJob::dispatch($network);
        }

        $this->info("Queued {$due->count()} network sync(s).");

        return self::SUCCESS;
    }

    private function isDue(Network $network): bool
    {
        return $network->last_synced_at === null
            || $network->last_synced_at->addMinutes((int) $network->sync_interval_minutes)->isPast();
    }
}
