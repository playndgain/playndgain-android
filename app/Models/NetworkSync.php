<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One catalogue run, written when it ends — SyncNetworkOffersJob.
 *
 * Including the runs that failed and the runs the collapse guard abandoned:
 * those are precisely the ones an operator goes looking for.
 */
class NetworkSync extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'status' => SyncStatus::class,
        // `{reason: {count, samples[]}}` — see the migration for why the
        // samples are capped.
        'skip_reasons' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * "reason: count" lines for the admin tooltip, worst offender first.
     *
     * Null rather than an empty string when nothing was skipped: a tooltip that
     * says nothing is a tooltip that should not appear.
     */
    public function skipSummary(): ?string
    {
        $reasons = collect($this->skip_reasons ?? [])
            ->sortByDesc(fn (mixed $bucket): int => (int) ($bucket['count'] ?? 0))
            ->map(fn (mixed $bucket, string $reason): string => $reason.': '.(int) ($bucket['count'] ?? 0));

        return $reasons->isEmpty() ? null : $reasons->implode("\n");
    }

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }
}
