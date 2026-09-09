<?php

namespace App\Models;

use App\Enums\PayoutStatementStatus;
use App\Models\Concerns\BelongsToPublisher;
use App\Services\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monthly statement — docs/06 §9.
 *
 * Frozen once generated: gross, chargebacks and adjustments are the ledger
 * activity of that calendar month and never recomputed. Status and the link
 * to a withdrawal are the only columns that move.
 */
class PayoutStatement extends Model
{
    use BelongsToPublisher;

    protected $guarded = [];

    protected $casts = [
        'period' => 'date',
        'due_date' => 'date',
        'gross' => 'decimal:6',
        'chargebacks' => 'decimal:6',
        'adjustments' => 'decimal:6',
        'net' => 'decimal:6',
        'minimum_payout' => 'decimal:2',
        'status' => PayoutStatementStatus::class,
        'generated_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<Withdrawal, $this> */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class);
    }

    /** "Aug, 2026" — the period as the history table shows it. */
    public function periodLabel(): string
    {
        return $this->period->format('M, Y');
    }

    /**
     * The withdrawal pays the full cleared balance, which can be more than this
     * month's net when earlier months were carried forward. Shown on the
     * latest statement the withdrawal settles, as the difference — so the
     * mismatch is explained rather than hidden.
     */
    public function carriedIn(): ?string
    {
        $withdrawal = $this->withdrawal;

        if ($withdrawal === null) {
            return null;
        }

        $siblings = static::query()
            ->withoutGlobalScopes()
            ->where('withdrawal_id', $withdrawal->id)
            ->get();

        if ($siblings->max('period')?->ne($this->period) ?? true) {
            return null;
        }

        $settled = $siblings->reduce(
            fn (string $carry, self $statement): string => bcadd($carry, Money::format($statement->net), Money::SCALE),
            '0.000000',
        );

        $difference = bcsub(Money::format($withdrawal->amount), $settled, Money::SCALE);

        return bccomp($difference, '0', Money::SCALE) === 0 ? null : $difference;
    }
}
