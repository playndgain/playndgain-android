<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Models\Concerns\BelongsToPublisher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

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

    /** @return HasMany<Withdrawal, $this> */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }
}
