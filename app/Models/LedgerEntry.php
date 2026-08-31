<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Models\Concerns\BelongsToPublisher;
use App\Services\Money\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Append-only. The only truth about money.
 *
 * `publishers.balance` is a cache written inside the same transaction as the
 * entry that changed it. A mistake here is corrected by an `adjustment` entry,
 * never by editing history.
 */
class LedgerEntry extends Model
{
    use BelongsToPublisher;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'type' => LedgerEntryType::class,
        'amount' => 'decimal:6',
        'balance_after' => 'decimal:6',
        'available_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Ledger entries are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Ledger entries cannot be deleted.');
        });
    }

    public static function createEarning(Conversion $conversion): self
    {
        return self::record(
            $conversion->publisher_id,
            LedgerEntryType::Earning,
            Money::format($conversion->payout),
            $conversion->clears_at,
            "Conversion {$conversion->trans_id} — {$conversion->offer_name}",
            $conversion,
        );
    }

    /**
     * Chargebacks are available immediately — `available_at` stays null — because
     * a reversal has to reduce what is withdrawable now, not next month.
     */
    public static function createChargeback(Conversion $conversion): self
    {
        return self::record(
            $conversion->publisher_id,
            LedgerEntryType::Chargeback,
            Money::negate($conversion->payout),
            null,
            "Reversal of conversion {$conversion->trans_id} — {$conversion->offer_name}",
            $conversion,
        );
    }

    /**
     * Written when an admin moves a request to `processing`, not when the
     * publisher requests it — docs/06 §4. Requesting is held out of the ledger
     * by withdrawableBalance() instead, so a rejection needs no reversing pair.
     */
    public static function createWithdrawal(Withdrawal $withdrawal, ?User $actor = null): self
    {
        return self::record(
            $withdrawal->publisher_id,
            LedgerEntryType::Withdrawal,
            Money::negate($withdrawal->amount),
            null,
            "Withdrawal #{$withdrawal->id} — {$withdrawal->method->value}",
            $withdrawal,
            $actor,
        );
    }

    /**
     * The only way to correct a balance. History is never edited, so the reason
     * is mandatory: an unexplained adjustment is indistinguishable from a bug.
     */
    public static function createAdjustment(Publisher $publisher, string $amount, string $reason, ?User $actor = null): self
    {
        return self::record(
            $publisher->id,
            LedgerEntryType::Adjustment,
            Money::format($amount),
            null,
            $reason,
            null,
            $actor,
        );
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The entry and the cached balance it produced are written together or not
     * at all. `publishers.balance` has no other writer.
     */
    private static function record(
        int $publisherId,
        LedgerEntryType $type,
        string $amount,
        ?DateTimeInterface $availableAt,
        string $description,
        ?Model $reference = null,
        ?User $createdBy = null,
    ): self {
        return DB::transaction(function () use ($publisherId, $type, $amount, $availableAt, $description, $reference, $createdBy) {
            // Two conversions for the same publisher landing at once would
            // otherwise compute balance_after from the same stale read, and the
            // snapshot column becomes fiction.
            $publisher = Publisher::query()->lockForUpdate()->findOrFail($publisherId);

            $balanceAfter = bcadd(Money::format($publisher->balance), $amount, Money::SCALE);

            $entry = self::create([
                'publisher_id' => $publisher->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'available_at' => $availableAt,
                'description' => Str::limit($description, 200),
                'created_by' => $createdBy?->id,
                'created_at' => now(),
            ]);

            // The row lock makes an assignment exact where an increment would
            // round-trip the amount through a float binding.
            $publisher->update([
                'balance' => $balanceAfter,
                'lifetime_earnings' => $type === LedgerEntryType::Earning
                    ? bcadd(Money::format($publisher->lifetime_earnings), $amount, Money::SCALE)
                    : Money::format($publisher->lifetime_earnings),
            ]);

            return $entry;
        });
    }
}
