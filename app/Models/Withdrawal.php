<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Enums\WithdrawalStatus;
use App\Models\Concerns\BelongsToPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    use BelongsToPublisher;

    protected $guarded = [];

    protected $hidden = ['payment_details'];

    protected $casts = [
        'amount' => 'decimal:6',
        'method' => PaymentMethodType::class,
        'payment_details' => 'encrypted',
        'status' => WithdrawalStatus::class,
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<User, $this> */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
