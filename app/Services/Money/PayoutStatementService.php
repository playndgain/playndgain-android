<?php

namespace App\Services\Money;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatementStatus;
use App\Enums\WithdrawalStatus;
use App\Models\LedgerEntry;
use App\Models\PayoutStatement;
use App\Models\Publisher;
use App\Models\Withdrawal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Monthly statements — docs/06 §9.
 *
 * A statement is the ledger activity of one calendar month, frozen the moment
 * it is generated. It says what the month earned; the withdrawal it links to
 * says what was paid. The two differ on purpose when earlier months were too
 * small to pay on their own — the modal shows that difference as "carried".
 */
final class PayoutStatementService
{
    /**
     * Compensating credits for rejected payouts are written before this class
     * gave them a reference, so the description is the only handle on them.
     */
    private const LEGACY_REJECTION_PREFIX = 'Rejected withdrawal #';

    public function __construct(private WithdrawalService $withdrawals) {}

    /** Last day of the month after the period — the due date the panel shows. */
    public static function dueDate(DateTimeInterface $period): CarbonImmutable
    {
        return CarbonImmutable::instance($period)->startOfMonth()->addMonth()->endOfMonth()->startOfDay();
    }

    /**
     * The last period a withdrawal can settle. A request on the 1st at 00:10
     * pays everything up to the previous month; a request mid-month cannot pay
     * the month in progress because it has not cleared.
     */
    public static function settledThrough(Withdrawal $withdrawal): CarbonImmutable
    {
        return CarbonImmutable::instance($withdrawal->requested_at ?? now())
            ->startOfMonth()
            ->subMonth();
    }

    /**
     * Idempotent: an existing (publisher, period) row is returned untouched.
     * A statement is a document — regenerating it would move numbers a
     * publisher may already have reconciled against.
     */
    public function generate(Publisher $publisher, DateTimeInterface $period): PayoutStatement
    {
        $period = CarbonImmutable::instance($period)->startOfMonth();

        $existing = $this->find($publisher->id, $period);

        if ($existing !== null) {
            return $existing;
        }

        $sums = $this->sums($publisher->id, $period);
        $minimum = $this->withdrawals->minimumPayout($publisher);

        [$status, $note] = $this->initialState($sums['net'], $minimum);

        try {
            return PayoutStatement::query()->withoutGlobalScopes()->create([
                'publisher_id' => $publisher->id,
                'period' => $period->toDateString(),
                'gross' => $sums['gross'],
                'chargebacks' => $sums['chargebacks'],
                'adjustments' => $sums['adjustments'],
                'net' => $sums['net'],
                'status' => $status,
                'due_date' => self::dueDate($period)->toDateString(),
                'minimum_payout' => $minimum,
                'note' => $note,
                'generated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two runs racing on the same month: the first one's row wins.
            return $this->find($publisher->id, $period) ?? throw new \RuntimeException('Statement vanished after a unique-key race.');
        }
    }

    /**
     * Every publisher with ledger activity in the period. Publishers are not
     * filtered by status here — a suspended publisher's earnings are still a
     * fact; only the withdrawal is gated.
     */
    public function generateForPeriod(DateTimeInterface $period): int
    {
        $period = CarbonImmutable::instance($period)->startOfMonth();
        $generated = 0;

        Publisher::query()
            ->whereIn('id', LedgerEntry::query()
                ->withoutGlobalScopes()
                ->select('publisher_id')
                ->where('created_at', '>=', $period)
                ->where('created_at', '<', $period->addMonth())
                ->distinct())
            ->chunkById(100, function ($publishers) use ($period, &$generated): void {
                foreach ($publishers as $publisher) {
                    if ($this->find($publisher->id, $period) === null) {
                        $this->generate($publisher, $period);
                        $generated++;
                    }
                }
            });

        return $generated;
    }

    /**
     * Link every statement the withdrawal pays. Returns how many were linked.
     *
     * `$through` overrides the natural reach and exists for the one-time
     * backfill of withdrawals made before payouts were monthly — nothing in
     * the live path passes it.
     */
    public function settle(Withdrawal $withdrawal, ?DateTimeInterface $through = null): int
    {
        $through = $through === null
            ? self::settledThrough($withdrawal)
            : CarbonImmutable::instance($through)->startOfMonth();

        return PayoutStatement::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $withdrawal->publisher_id)
            ->whereNull('withdrawal_id')
            ->whereIn('status', [PayoutStatementStatus::Pending, PayoutStatementStatus::Carried])
            ->where('period', '<=', $through->toDateString())
            ->update([
                'withdrawal_id' => $withdrawal->id,
                'status' => PayoutStatementStatus::Pending,
                'note' => null,
                'updated_at' => now(),
            ]);
    }

    public function markPaid(Withdrawal $withdrawal): void
    {
        PayoutStatement::query()
            ->withoutGlobalScopes()
            ->where('withdrawal_id', $withdrawal->id)
            ->update([
                'status' => PayoutStatementStatus::Paid,
                'paid_at' => $withdrawal->paid_at ?? now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * A rejected payout hands its statements back. The money never left (or
     * was credited back), so the next monthly run settles them again.
     */
    public function unlink(Withdrawal $withdrawal, string $reason): void
    {
        PayoutStatement::query()
            ->withoutGlobalScopes()
            ->where('withdrawal_id', $withdrawal->id)
            ->update([
                'withdrawal_id' => null,
                'status' => PayoutStatementStatus::Pending,
                'paid_at' => null,
                'note' => "Withdrawal #{$withdrawal->id} rejected: {$reason}",
                'updated_at' => now(),
            ]);
    }

    /**
     * Record why the monthly run could not open a withdrawal, on the
     * statement the run was for.
     */
    public function noteBlocked(Publisher $publisher, DateTimeInterface $period, string $reason, bool $carried): void
    {
        $statement = $this->find($publisher->id, CarbonImmutable::instance($period)->startOfMonth());

        if ($statement === null || $statement->status === PayoutStatementStatus::Paid || $statement->withdrawal_id !== null) {
            return;
        }

        $statement->update([
            'status' => $carried ? PayoutStatementStatus::Carried : PayoutStatementStatus::Pending,
            'note' => $reason,
        ]);
    }

    /**
     * Ledger activity of one calendar month, by `created_at`. Payout traffic
     * is not activity: `withdrawal` entries and the compensating adjustment a
     * rejected payout writes are both excluded.
     *
     * @return array{gross: string, chargebacks: string, adjustments: string, net: string}
     */
    public function sums(int $publisherId, DateTimeInterface $period): array
    {
        $period = CarbonImmutable::instance($period)->startOfMonth();

        $rows = LedgerEntry::query()
            ->withoutGlobalScopes()
            ->selectRaw('type, SUM(amount) AS total')
            ->where('publisher_id', $publisherId)
            ->where('created_at', '>=', $period)
            ->where('created_at', '<', $period->addMonth())
            ->where('type', '!=', LedgerEntryType::Withdrawal->value)
            ->where(fn ($query) => $query
                ->where('type', '!=', LedgerEntryType::Adjustment->value)
                ->orWhere(fn ($adjustment) => $adjustment
                    ->where('type', LedgerEntryType::Adjustment->value)
                    ->where(fn ($q) => $q->whereNull('reference_type')->orWhere('reference_type', '!=', (new Withdrawal)->getMorphClass()))
                    ->where(fn ($q) => $q->whereNull('description')->orWhere('description', 'not like', self::LEGACY_REJECTION_PREFIX.'%'))))
            ->groupBy('type')
            ->pluck('total', 'type');

        $gross = bcadd(
            Money::format($rows[LedgerEntryType::Earning->value] ?? '0'),
            Money::format($rows[LedgerEntryType::Referral->value] ?? '0'),
            Money::SCALE,
        );
        $chargebacks = Money::format($rows[LedgerEntryType::Chargeback->value] ?? '0');
        $adjustments = Money::format($rows[LedgerEntryType::Adjustment->value] ?? '0');

        return [
            'gross' => $gross,
            'chargebacks' => $chargebacks,
            'adjustments' => $adjustments,
            'net' => bcadd(bcadd($gross, $chargebacks, Money::SCALE), $adjustments, Money::SCALE),
        ];
    }

    /**
     * Everything cleared and not yet paid: the ledger's cleared balance plus
     * what an approved-but-unpaid withdrawal already debited from it.
     */
    public function pendingPayment(Publisher $publisher): string
    {
        $processing = $publisher->withdrawals()
            ->where('status', WithdrawalStatus::Processing)
            ->sum('amount');

        $pending = bcadd($publisher->clearedBalance(), Money::format($processing), Money::SCALE);

        return bccomp($pending, '0', Money::SCALE) < 0 ? Money::format(0) : $pending;
    }

    private function find(int $publisherId, CarbonImmutable $period): ?PayoutStatement
    {
        return PayoutStatement::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $publisherId)
            ->where('period', $period->toDateString())
            ->first();
    }

    /** @return array{PayoutStatementStatus, ?string} */
    private function initialState(string $net, string $minimum): array
    {
        if (bccomp($net, '0', Money::SCALE) <= 0) {
            return [PayoutStatementStatus::Carried, 'Nothing to pay this period — carried forward.'];
        }

        if (bccomp($net, $minimum, Money::SCALE) < 0) {
            return [PayoutStatementStatus::Carried, "Below the \${$minimum} minimum — carried forward."];
        }

        return [PayoutStatementStatus::Pending, null];
    }
}
