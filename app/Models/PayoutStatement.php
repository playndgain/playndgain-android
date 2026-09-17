<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatementStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Concerns\BelongsToPublisher;
use App\Services\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A monthly statement — docs/06 §9.
 *
 * Frozen once generated: gross and adjustments are the ledger activity of
 * that calendar month and never recomputed. Chargebacks on a pending
 * statement whose withdrawal is still unpaid keep moving with the open
 * request. Status and the link to a withdrawal are the other columns that
 * move.
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

    /**
     * Per-line money for the invoice: earnings grouped by app, every
     * chargeback and adjustment listed. Chargebacks stay open through
     * paid_at (or now) so a late reversal on an unpaid request is visible.
     *
     * @return array{earnings: list<array{description: string, amount: string}>, chargebacks: list<array{at: string, description: string, app: ?string, offer: ?string, amount: string}>, adjustments: list<array{at: string, description: string, amount: string}>}
     */
    public function publisherBreakdown(): array
    {
        $periodStart = CarbonImmutable::instance($this->period)->startOfMonth();
        $periodEnd = CarbonImmutable::instance($this->period)->endOfMonth();
        $chargebackUntil = $this->chargebackCutoff();

        $entries = LedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $this->publisher_id)
            ->whereIn('type', [
                LedgerEntryType::Earning,
                LedgerEntryType::Referral,
                LedgerEntryType::Chargeback,
                LedgerEntryType::Adjustment,
            ])
            ->where('created_at', '>=', $periodStart)
            ->where(function ($query) use ($periodEnd, $chargebackUntil): void {
                $query->where(function ($inPeriod) use ($periodEnd): void {
                    $inPeriod
                        ->whereIn('type', [
                            LedgerEntryType::Earning->value,
                            LedgerEntryType::Referral->value,
                            LedgerEntryType::Adjustment->value,
                        ])
                        ->where('created_at', '<=', $periodEnd);
                })->orWhere(function ($chargebacks) use ($chargebackUntil): void {
                    $chargebacks
                        ->where('type', LedgerEntryType::Chargeback->value)
                        ->where('created_at', '<=', $chargebackUntil);
                });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $conversions = $this->conversionsFor($entries);
        $earnings = [];
        $chargebacks = [];
        $adjustments = [];

        foreach ($entries as $entry) {
            $conversion = $entry->reference_type === (new Conversion)->getMorphClass()
                ? $conversions->get($entry->reference_id)
                : null;

            if ($entry->type === LedgerEntryType::Chargeback) {
                $chargebacks[] = [
                    'at' => $entry->created_at->timezone(config('app.timezone'))->format('M j, Y H:i'),
                    'description' => $this->lineLabel($entry, $conversion),
                    'app' => $this->appLabel($conversion),
                    'offer' => $conversion?->offer_name,
                    'amount' => Money::format($entry->amount),
                ];

                continue;
            }

            if ($entry->type === LedgerEntryType::Adjustment) {
                if ($entry->reference_type === (new Withdrawal)->getMorphClass()
                    || (is_string($entry->description) && str_starts_with($entry->description, 'Rejected withdrawal #'))) {
                    continue;
                }

                $adjustments[] = [
                    'at' => $entry->created_at->timezone(config('app.timezone'))->format('M j, Y H:i'),
                    'description' => $entry->description ?: 'Adjustment',
                    'amount' => Money::format($entry->amount),
                ];

                continue;
            }

            $key = (string) ($conversion !== null ? $conversion->app_id : 0);
            if (! isset($earnings[$key])) {
                $earnings[$key] = [
                    'description' => $this->appLabel($conversion) ?? 'Publisher earnings',
                    'amount' => '0.000000',
                ];
            }
            $earnings[$key]['amount'] = bcadd($earnings[$key]['amount'], Money::format($entry->amount), Money::SCALE);
        }

        return [
            'earnings' => array_values($earnings),
            'chargebacks' => $chargebacks,
            'adjustments' => $adjustments,
        ];
    }

    /**
     * One row per offer that reversed — the invoice the publisher opens
     * from Payment history. Count + total, not every conversion line.
     *
     * @return list<array{app: string, offer: string, description: string, count: int, amount: string}>
     */
    public function chargebacksByOffer(): array
    {
        $offers = [];

        foreach ($this->publisherBreakdown()['chargebacks'] as $line) {
            $app = $line['app'] ?? null;
            $offer = $line['offer'] ?? null;
            $key = ($app ?? '').'|'.($offer ?? $line['description']);

            if (! isset($offers[$key])) {
                $offers[$key] = [
                    'app' => $app ?? '—',
                    'offer' => $offer ?? $line['description'],
                    'description' => $line['description'],
                    'count' => 0,
                    'amount' => '0.000000',
                ];
            }

            $offers[$key]['count']++;
            $offers[$key]['amount'] = bcadd($offers[$key]['amount'], $line['amount'], Money::SCALE);
        }

        $rows = array_values($offers);
        usort($rows, fn (array $a, array $b): int => bccomp($a['amount'], $b['amount'], Money::SCALE));

        return $rows;
    }

    private function chargebackCutoff(): DateTimeInterface
    {
        $withdrawal = $this->withdrawal;

        if ($withdrawal?->status === WithdrawalStatus::Requested) {
            return now();
        }

        if ($withdrawal?->paid_at !== null) {
            return $withdrawal->paid_at;
        }

        return CarbonImmutable::instance($this->period)->endOfMonth();
    }

    /**
     * @param  Collection<int, LedgerEntry>  $entries
     * @return Collection<int, Conversion>
     */
    private function conversionsFor(Collection $entries): Collection
    {
        $ids = $entries
            ->where('reference_type', (new Conversion)->getMorphClass())
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Conversion::query()
            ->withoutGlobalScopes()
            ->with(['app' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'app_id', 'name'])])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    private function lineLabel(LedgerEntry $entry, ?Conversion $conversion): string
    {
        $app = $this->appLabel($conversion);
        $offer = $conversion?->offer_name;

        if ($app !== null && $offer) {
            return $app.' — '.$offer;
        }

        return $app ?? $offer ?? (string) ($entry->description ?: 'Chargeback');
    }

    private function appLabel(?Conversion $conversion): ?string
    {
        $app = $conversion?->app;

        if ($app !== null) {
            return '#'.$app->app_id.' '.$app->name;
        }

        return null;
    }
}
