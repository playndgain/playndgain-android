<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Enums\WithdrawalStatus;
use App\Models\Concerns\BelongsToPublisher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Withdrawal extends Model
{
    use BelongsToPublisher;

    protected $guarded = [];

    protected $hidden = ['payment_details'];

    protected $casts = [
        'amount' => 'decimal:6',
        'method' => PaymentMethodType::class,
        'payment_details' => 'encrypted:array',
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

    /**
     * The monthly statements this withdrawal settles — usually one, more when
     * earlier months were carried forward below the minimum.
     *
     * @return HasMany<PayoutStatement, $this>
     */
    public function statements(): HasMany
    {
        return $this->hasMany(PayoutStatement::class);
    }

    /**
     * The destination snapshotted at request time — never the payment method
     * as it is now. Both panels read it through here.
     *
     * @return array<string, mixed>
     */
    public function destination(): array
    {
        // Read raw: the cast promises an array, but a row encrypted under a
        // key this environment no longer has decrypts to null (below).
        $details = $this->getAttribute('payment_details');

        return is_array($details) ? $details : [];
    }

    /**
     * A row encrypted under a previous APP_KEY is unreadable and stays so.
     * Throwing here 500s the one page that tells a publisher where their
     * money goes — treat it as missing so they can enter it again. Same
     * rule as IpProvider.
     */
    public function fromEncryptedString($value)
    {
        try {
            return parent::fromEncryptedString($value);
        } catch (DecryptException) {
            Log::warning(static::class.' encrypted attribute could not be decrypted', ['id' => $this->getKey()]);

            return null;
        }
    }
}
