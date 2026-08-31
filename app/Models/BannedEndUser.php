<?php

namespace App\Models;

use App\Enums\BannedBy;
use App\Models\Concerns\BelongsToPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BannedEndUser extends Model
{
    use BelongsToPublisher;

    /** Auto-bans expire. Permanent bans are a human decision. */
    public const AUTO_BAN_DAYS = 7;

    protected $guarded = [];

    protected $casts = [
        'banned_by' => BannedBy::class,
        'expires_at' => 'datetime',
    ];

    /**
     * @param  Builder<BannedEndUser>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
