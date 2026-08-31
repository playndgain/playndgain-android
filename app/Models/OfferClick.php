<?php

namespace App\Models;

use App\Enums\DeviceType;
use App\Models\Concerns\BelongsToPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class OfferClick extends Model
{
    use BelongsToPublisher;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'device_type' => DeviceType::class,
        'blocked' => 'boolean',
        'is_test' => 'boolean',
        'converted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $click): void {
            // UUIDv4, never sequential, never guessable — it is the join key
            // for the entire conversion path.
            $click->click_id ??= (string) Str::uuid();
        });
    }

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

    /** @return HasOne<Conversion, $this> */
    public function conversion(): HasOne
    {
        return $this->hasOne(Conversion::class, 'offer_click_id');
    }
}
