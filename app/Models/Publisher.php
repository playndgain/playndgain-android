<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Enums\PublisherStatus;
use App\Enums\WithdrawalStatus;
use App\Services\Money\Money;
use Database\Factories\PublisherFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Publisher extends Model
{
    /** @use HasFactory<PublisherFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Public-facing IDs start here and count up. Shown in the UI as `#10042`. */
    public const FIRST_PUB_ID = 10000;

    protected $guarded = [];

    /**
     * Which emails a publisher wants — docs/08 §14. The keys are the whole
     * definition: the profile form builds its checkboxes from them and
     * `wantsNotification()` rejects anything not listed, so adding a
     * notification type is one entry here and no migration.
     */
    public const NOTIFICATION_TYPES = [
        'postback_failures' => 'Postback delivery failures',
        'withdrawal_status' => 'Withdrawal status changes',
        'account_status' => 'Account status changes',
        'monthly_summary' => 'Monthly earnings summary',
    ];

    protected $casts = [
        'status' => PublisherStatus::class,
        'rev_share' => 'decimal:2',
        'min_payout' => 'decimal:2',
        'balance' => 'decimal:6',
        'lifetime_earnings' => 'decimal:6',
        'approved_at' => 'datetime',
        'notification_preferences' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $publisher): void {
            // ponytail: max+1 under the unique index. Publisher signups are a
            // handful a day and a race just throws — swap for a sequence table
            // if that ever stops being true.
            $publisher->pub_id ??= (static::withTrashed()->max('pub_id') ?? self::FIRST_PUB_ID - 1) + 1;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<App, $this> */
    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    /** @return HasMany<Conversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return HasMany<Withdrawal, $this> */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /** @return HasMany<PaymentMethod, $this> */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /** @return HasMany<Blacklist, $this> */
    public function blacklists(): HasMany
    {
        return $this->hasMany(Blacklist::class);
    }

    /** @return HasMany<BannedEndUser, $this> */
    public function bannedEndUsers(): HasMany
    {
        return $this->hasMany(BannedEndUser::class);
    }

    public function isApproved(): bool
    {
        return $this->status === PublisherStatus::Approved;
    }

    public function isBanned(): bool
    {
        return $this->status === PublisherStatus::Banned;
    }

    public function isSuspended(): bool
    {
        return $this->status === PublisherStatus::Suspended;
    }

    /**
     * Everything past its hold window — docs/06 §2.
     *
     * Internal. The panel never displays this: no ledger entry is written when
     * a withdrawal is merely requested, so cleared still contains money the
     * publisher has already asked for.
     */
    public function clearedBalance(): string
    {
        $sum = $this->ledgerEntries()
            ->where(fn (Builder $query) => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->sum('amount');

        return Money::format($sum);
    }

    /**
     * Cleared minus what has been requested but not yet debited. Labelled
     * "Available" in the panel.
     *
     * Only `requested` is subtracted: `approve()` writes the ledger entry the
     * moment a withdrawal turns `processing`, so from then on the cleared
     * balance already excludes it — subtracting it again showed publishers an
     * Available figure short by the payout in flight.
     */
    public function withdrawableBalance(): string
    {
        $open = $this->withdrawals()
            ->where('status', WithdrawalStatus::Requested)
            ->sum('amount');

        return bcsub($this->clearedBalance(), Money::format($open), Money::SCALE);
    }

    /** Earnings still inside their hold window. */
    public function pendingBalance(): string
    {
        $sum = $this->ledgerEntries()
            ->where('type', LedgerEntryType::Earning)
            ->where('available_at', '>', now())
            ->sum('amount');

        return Money::format($sum);
    }

    /**
     * Null means "never opened the preferences page", which is not the same as
     * "opted out" — an unset preference is on. Anything not in
     * NOTIFICATION_TYPES is off, so a stale key left in the JSON by a removed
     * notification cannot switch a new one on by sharing its name.
     */
    public function wantsNotification(string $type): bool
    {
        if (! array_key_exists($type, self::NOTIFICATION_TYPES)) {
            return false;
        }

        $preferences = $this->notification_preferences;

        if (! is_array($preferences) || ! array_key_exists($type, $preferences)) {
            return true;
        }

        return (bool) $preferences[$type];
    }

    /**
     * Their own timezone, or the platform's. Drives every date in the panel.
     *
     * Not named `timezone()` — Eloquent resolves an unknown property through a
     * same-named method as a relation, and a column and a relation accessor
     * that share a name is a bug waiting for the first null.
     */
    public function resolvedTimezone(): string
    {
        return is_string($this->timezone) && $this->timezone !== ''
            ? $this->timezone
            : (string) config('app.timezone');
    }
}
