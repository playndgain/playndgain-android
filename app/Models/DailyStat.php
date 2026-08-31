<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStat extends Model
{
    use BelongsToPublisher;

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'revenue' => 'decimal:6',
        'payout' => 'decimal:6',
    ];

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }
}
