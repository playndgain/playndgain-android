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
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }
}
