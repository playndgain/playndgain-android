<?php

namespace App\Jobs;

use App\Enums\NetworkIntegration;
use App\Enums\OfferStatus;
use App\Enums\SyncStatus;
use App\Models\Network;
use App\Models\NetworkSync;
use App\Models\Offer;
use App\Networks\DTO\OfferDTO;
use App\Networks\NetworkDriverManager;
use App\Networks\OfferCacheFlusher;
use App\Support\AdminAlert;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class SyncNetworkOffersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    /**
     * docs/11 §7: three consecutive sync failures is an email.
     *
     * One is a network having a bad minute — the sweep runs every fifteen
     * minutes and transient 5xx from a partner API is ordinary traffic. Three
     * in a row is a catalogue we have stopped receiving, and the next thing
     * anyone notices is a wall going empty.
     */
    private const FAILURES_BEFORE_ALERT = 3;

    public function __construct(public Network $network)
    {
        // Never the `postbacks` queue — a ten-minute catalogue pull must not sit
        // in front of a conversion.
        $this->onQueue('sync');

        // After the writer commits. A save that queues this job in the same
        // request would otherwise load the network row before the credentials
        // landed, and SerializesModels would hydrate the old keys.
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "sync-network-{$this->network->id}";
    }

    public function handle(NetworkDriverManager $manager): void
    {
        // Truncated to the second: `last_seen_at` is a second-precision column,
        // and a row written by this run must never compare as older than it.
        $runAt = now()->startOfSecond();

        // Measured separately from `$runAt`, which is deliberately blunt. A
        // sub-second pull would otherwise log as having taken no time at all.
        $startedAt = microtime(true);

        /** @var array<int, true> $seen */
        $seen = [];
        $created = 0;

        // Resolving the driver is inside the try, and the two values above are
        // measured before it, for one reason: a `networks` row naming a driver
        // class that no longer exists throws here, and outside the try that
        // throw wrote no status, no sync row and no alert — the run existed
        // only in `failed_jobs`. Removing a driver is a normal thing to do (four
        // were removed on this branch alone) and a typo in the `driver` column
        // is a normal thing to have, so the failure needs the same treatment as
        // a network that times out.
        try {
            $driver = $manager->make($this->network);

            // A channel whose offers are typed in by hand has no catalogue to
            // compare against. Running the sweep below would read "the network
            // returned nothing" and deactivate every manual offer on it. The
            // same skip applies when an admin chose embed-only on a driver that
            // *can* sync — empty credentials would otherwise fail the run.
            if (! $driver->syncsCatalogue() || $this->network->integration === NetworkIntegration::Embed) {
                return;
            }

            foreach ($driver->fetchOffers() as $dto) {
                $offer = $this->upsert($dto, $runAt);

                $seen[(int) $offer->id] = true;
                $created += $offer->wasRecentlyCreated ? 1 : 0;
            }
        } catch (Throwable $e) {
            $this->network->update([
                'last_sync_status' => SyncStatus::Failed,
                'last_sync_error' => Str::limit($e->getMessage(), 500),
            ]);

            // Recorded before the rethrow, with the partial counts: the offers
            // upserted before the network died are in the database either way,
            // and a run that logged nothing is a run nobody can explain.
            $this->recordRun($runAt, $startedAt, $seen, $created, 0);
            $this->alertOnRepeatedFailure();

            throw $e;
        }

        if ($this->catalogueCollapsed(count($seen))) {
            // The guard has already written the reason onto the network; this
            // is the run the operator will come looking for, so it gets a row
            // like any other — nothing was deactivated, which is the point.
            $this->recordRun($runAt, $startedAt, $seen, $created, 0);

            return;
        }

        // Anything not seen this run is gone from the network's catalogue.
        // Deactivate — never delete. Clicks and conversions reference these rows
        // for the lifetime of the platform.
        $deactivated = Offer::query()
            ->where('network_id', $this->network->id)
            ->where('status', OfferStatus::Active)
            ->where(fn (Builder $query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', $runAt))
            ->update(['status' => OfferStatus::Inactive]);

        $this->network->update([
            'last_synced_at' => $runAt,
            'last_sync_status' => SyncStatus::Ok,
            'last_sync_error' => null,
            'offers_count' => count($seen),
        ]);

        $this->recordRun($runAt, $startedAt, $seen, $created, $deactivated);

        OfferCacheFlusher::flushForNetwork($this->network);
    }

    /**
     * The Sync Log row — docs/07 §4.
     *
     * Status and error are read back off the network rather than passed in:
     * every branch above has already written its verdict there, and reading it
     * is how the log and the networks table can never disagree.
     *
     * @param  array<int, true>  $seen
     */
    private function recordRun(Carbon $runAt, float $startedAt, array $seen, int $created, int $deactivated): void
    {
        NetworkSync::create([
            'network_id' => $this->network->id,
            'started_at' => $runAt,
            'finished_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'offers_seen' => count($seen),
            'offers_created' => $created,
            'offers_updated' => count($seen) - $created,
            'offers_deactivated' => $deactivated,
            'status' => $this->network->last_sync_status ?? SyncStatus::Ok,
            'error' => $this->network->last_sync_error,
            'created_at' => now(),
        ]);
    }

    /**
     * The streak is read from `network_syncs` rather than counted on the
     * network row, because the log is already the record of what happened and a
     * counter column would be a second one to keep in step. `tries = 2` means a
     * failing sweep writes two rows per scheduled run, so the threshold is
     * reached inside two scheduled runs rather than three — which is the right
     * direction to be wrong in.
     */
    private function alertOnRepeatedFailure(): void
    {
        $recent = NetworkSync::query()
            ->where('network_id', $this->network->id)
            ->orderByDesc('id')
            ->limit(self::FAILURES_BEFORE_ALERT)
            ->pluck('status');

        if ($recent->count() < self::FAILURES_BEFORE_ALERT) {
            return;
        }

        if ($recent->contains(fn (SyncStatus $status): bool => $status !== SyncStatus::Failed)) {
            return;
        }

        AdminAlert::raise('network.sync_failing', $this->network, [
            'consecutive' => self::FAILURES_BEFORE_ALERT,
            'error' => $this->network->last_sync_error,
        ]);
    }

    private function upsert(OfferDTO $dto, Carbon $runAt): Offer
    {
        $offer = Offer::query()->firstOrNew([
            'network_id' => $this->network->id,
            'external_id' => $dto->externalId,
        ]);

        $offer->fill([
            // Clamped, not validated away: a title a network pads past our
            // column is cosmetic, and refusing the row would fail the insert and
            // take the whole catalogue run with it. URLs are never clamped —
            // a shortened URL is a broken one — their columns are `text`.
            'name' => Str::limit($dto->name, 255, ''),
            'description' => $dto->description,
            'instructions' => $dto->instructions,
            'icon_url' => $dto->iconUrl,
            'preview_url' => $dto->previewUrl,
            'tracking_url' => $dto->trackingUrl,
            'payout' => $dto->payout,
            'conversion_type' => $dto->conversionType,
            'countries' => $dto->countries,
            'devices' => $dto->devices,
            'os_min_version' => Str::limit($dto->osMinVersion ?? '', 255, '') ?: null,
            'categories' => $dto->categories,
            'is_multi_reward' => $dto->isMultiReward,
            'goals' => $dto->goals === [] ? null : $dto->goals,
            'daily_cap' => $dto->dailyCap,
            'last_seen_at' => $runAt,
        ]);

        // An admin block outlives a sync. Re-activating here would silently undo
        // it on the next catalogue pull.
        if ($offer->status !== OfferStatus::Blocked) {
            $offer->status = OfferStatus::Active;
        }

        $offer->first_seen_at = $offer->first_seen_at ?? $runAt;
        $offer->save();

        // The row, not its key: `wasRecentlyCreated` is the only thing that can
        // tell new inventory from refreshed inventory, and it lives on the model.
        return $offer;
    }

    /**
     * A network answering 200 OK with an empty catalogue — credentials silently
     * expired, or a bad morning on their side — would deactivate every offer we
     * hold from them and empty the wall of every publisher running it. The
     * failure this refuses is silent, total, and revenue-destroying, so the run
     * is abandoned with the offers untouched and a human is told.
     */
    private function catalogueCollapsed(int $seenCount): bool
    {
        $previous = (int) $this->network->offers_count;

        if ($previous <= 20 || $seenCount >= $previous * 0.2) {
            return false;
        }

        $this->network->update([
            'last_sync_status' => SyncStatus::Failed,
            'last_sync_error' => "Catalogue dropped from {$previous} to {$seenCount} — refusing to deactivate. Manual review required.",
        ]);

        AdminAlert::raise('network.catalogue_collapse', $this->network, [
            'driver' => (string) $this->network->driver,
            'previous' => $previous,
            'current' => $seenCount,
        ]);

        return true;
    }
}
