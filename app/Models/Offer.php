<?php

namespace App\Models;

use App\Enums\ConversionType;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Offers are never hard-deleted — clicks and conversions reference them for the
 * lifetime of the platform. A vanished offer goes `inactive`.
 */
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'payout' => 'decimal:6',
        'conversion_type' => ConversionType::class,
        'countries' => 'array',
        'devices' => 'array',
        'categories' => 'array',
        'is_multi_reward' => 'boolean',
        'goals' => 'array',
        'status' => OfferStatus::class,
        'is_featured' => 'boolean',
        'last_seen_at' => 'datetime',
        'first_seen_at' => 'datetime',
    ];

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * Inventory whose supplier is switched on. A disabled or erroring network
     * still owns the rows — they are history — but they are not catalogue.
     *
     * @param  Builder<Offer>  $query
     */
    public function scopeOnActiveNetwork(Builder $query): void
    {
        $query->whereHas('network', fn (Builder $network) => $network->where('status', NetworkStatus::Active));
    }

    public function isLive(): bool
    {
        return $this->status === OfferStatus::Active
            && ($this->network?->isActive() ?? false);
    }

    /** @return HasMany<OfferClick, $this> */
    public function offerClicks(): HasMany
    {
        return $this->hasMany(OfferClick::class);
    }

    /** @return HasMany<Conversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }
}
