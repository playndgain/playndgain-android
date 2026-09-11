<?php

namespace App\Models;

use App\Enums\IdentityKind;
use App\Enums\IdentitySignal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMatch extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'scope' => IdentityKind::class,
        'signal' => IdentitySignal::class,
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** @return BelongsTo<Publisher, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /** @return BelongsTo<User, $this> */
    public function leftUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'left_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rightUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'right_user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subHours($hours));
    }
}
