<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Models\Concerns\BelongsToPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use BelongsToPublisher;

    protected $guarded = [];

    protected $hidden = ['details'];

    protected $casts = [
        'type' => PaymentMethodType::class,
        'details' => 'encrypted:array',
        'is_default' => 'boolean',
        'verified_at' => 'datetime',
    ];

    /** @return HasMany<Withdrawal, $this> */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }
}
