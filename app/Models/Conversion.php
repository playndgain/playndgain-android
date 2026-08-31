<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use App\Models\Concerns\BelongsToPublisher;
use Database\Factories\ConversionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Conversion extends Model
{
    use BelongsToPublisher;

    /** @use HasFactory<ConversionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'revenue' => 'decimal:6',
        'payout' => 'decimal:6',
        'rev_share_applied' => 'decimal:2',
        'currency_amount' => 'decimal:4',
        'status' => ConversionStatus::class,
        'is_test' => 'boolean',
        'clears_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /** @return BelongsTo<OfferClick, $this> */
    public function offerClick(): BelongsTo
    {
        return $this->belongsTo(OfferClick::class);
    }

    /** @return HasMany<OutgoingPostback, $this> */
    public function outgoingPostbacks(): HasMany
    {
        return $this->hasMany(OutgoingPostback::class);
    }

    /** @return MorphOne<LedgerEntry, $this> */
    public function ledgerEntry(): MorphOne
    {
        return $this->morphOne(LedgerEntry::class, 'reference');
    }

    public function isCleared(): bool
    {
        return $this->clears_at !== null && $this->clears_at->isPast();
    }
}
