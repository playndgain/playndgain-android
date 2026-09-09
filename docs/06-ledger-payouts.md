# 06 — Ledger & Payouts

Money accounting. The rules here are the ones that get audited when a publisher disputes a balance.

---

## 1. The Ledger Rule

`ledger_entries` is **append-only** and is the **only** truth about money.

`publishers.balance` is a cache. It exists because `SUM(amount)` over millions of rows on every dashboard load is not viable. It is written in the same database transaction as the entry that changed it, and it is re-derived nightly with an alert on any drift.

```php
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;   // no updated_at column at all

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }
}
```

A mistake is corrected with a compensating `adjustment` entry, never by editing history. This is not ceremony — it is the only way to answer "what did this balance look like on March 3rd" six months later.

### Entry types

| Type | Sign | Written when |
|---|---|---|
| `earning` | + | A conversion is approved |
| `chargeback` | − | A conversion is reversed |
| `withdrawal` | − | A withdrawal moves to `processing` |
| `adjustment` | ± | Admin manual correction. **Requires a reason.** |
| `referral` | + | Reserved. Not built in v1. |

### Writing an entry

Always inside a transaction, always with the balance update:

```php
public static function createEarning(Conversion $conversion): self
{
    return DB::transaction(function () use ($conversion) {
        $publisher = Publisher::lockForUpdate()->find($conversion->publisher_id);

        $entry = self::create([
            'publisher_id'   => $publisher->id,
            'type'           => 'earning',
            'amount'         => $conversion->payout,
            'balance_after'  => $publisher->balance + $conversion->payout,
            'reference_type' => Conversion::class,
            'reference_id'   => $conversion->id,
            'available_at'   => $conversion->clears_at,
            'description'    => "Conversion {$conversion->trans_id} — {$conversion->offer_name}",
        ]);

        $publisher->increment('balance', $conversion->payout);
        $publisher->increment('lifetime_earnings', $conversion->payout);

        return $entry;
    });
}
```

`lockForUpdate()` matters. Two conversions for the same publisher landing simultaneously will otherwise compute `balance_after` from the same stale read, and the snapshot column becomes fiction.

---

## 2. Balance Definitions

Four numbers, four meanings. Conflating them is the top support question in this business, so they are named distinctly in code and only three are ever shown.

| Name | Definition | Shown to publisher |
|---|---|---|
| **Cleared** | Ledger sum of everything past its hold window. An internal figure. | No |
| **Withdrawable** | Cleared **minus withdrawals still `requested`**. Once a withdrawal is `processing` its ledger entry has already left cleared, so it is not subtracted again. | **Yes — labelled "Available"** |
| **Pending** | Earnings still inside their hold window. | Yes |
| **Lifetime** | All positive earnings ever, ignoring reversals and withdrawals. A vanity number. | Yes |

```php
/** Everything past its hold window. Internal — do not display. */
public function clearedBalance(): float
{
    return (float) $this->ledgerEntries()
        ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
        ->sum('amount');
}

/** What can actually be withdrawn right now. This is what the panel labels "Available". */
public function withdrawableBalance(): float
{
    // Only `requested`: a `processing` withdrawal is already in the ledger.
    $open = (float) $this->withdrawals()
        ->where('status', 'requested')
        ->sum('amount');

    return $this->clearedBalance() - $open;
}

public function pendingBalance(): float
{
    return (float) $this->ledgerEntries()
        ->where('type', 'earning')
        ->where('available_at', '>', now())
        ->sum('amount');
}
```

Withdrawals and chargebacks have `available_at = null`, so they count against the cleared balance immediately. That is deliberate: a reversal must reduce what is withdrawable right now, not next month.

> **The panel never displays `clearedBalance()`.** No ledger entry is written when a withdrawal is merely *requested* (§4), so cleared still includes money the publisher has already asked for. Showing that number next to a Withdraw button produces a form that rejects the amount it just displayed.

---

## 3. Hold Window (calendar month)

Every conversion gets `clears_at` = the first instant of the **next** calendar month (`created_at` → `startOfMonth()->addMonth()`), in `config('app.timezone')` (UTC). A 15 January conversion clears 1 February 00:00; a conversion on the 1st waits until the following month.

There is no per-publisher days override. `publishers.hold_days` and `settings.default_hold_days` are leftover columns and are not read.

Chargebacks still ignore the hold: `available_at` is null, they debit immediately, and the withdrawable balance can go negative. Future earnings pay that down. The monthly withdrawal job at 00:10 on the 1st runs after that month's earnings have already cleared.

---

## 4. Withdrawals

### State machine

```
requested ──► processing ──► paid
     │             │
     └──► rejected └──► rejected
```

Terminal states are `paid` and `rejected`. Nothing moves backwards.

### Requesting

There is no publisher-initiated request. `withdrawals:request-monthly` opens one withdrawal per approved publisher on the 1st at 00:10, for the full withdrawable balance, on the Active payment method (§9). The rules it applies are the same ones the admin panel applies to a hand-opened request:

1. Publisher is `approved`.
2. `withdrawableBalance() ≥ max(publishers.min_payout, 20.00)`.
3. Amount ≤ `withdrawableBalance()`.
4. A payment method exists and one is Active (`is_default`).
5. No other withdrawal is currently `requested` or `processing`. **One open request at a time** — concurrent requests against the same balance are a double-spend waiting to happen.

A publisher who fails a rule gets a skip mail, ops get one error mail for the run, and the reason is written on that month's statement so the Payments page says why nothing was requested.

```php
DB::transaction(function () use ($publisher, $amount, $method) {
    $publisher = Publisher::lockForUpdate()->find($publisher->id);

    if ($publisher->withdrawableBalance() < $amount) {
        throw new InsufficientBalanceException;
    }

    return Withdrawal::create([
        'publisher_id'       => $publisher->id,
        'payment_method_id'  => $method->id,
        'amount'             => $amount,
        'method'             => $method->type,
        'payment_details'    => $method->details,   // snapshot — encrypted
        'status'             => 'requested',
        'requested_at'       => now(),
    ]);
});
```

**Payment details are snapshotted onto the withdrawal** (`encrypted:array` cast, read through `Withdrawal::destination()`). If the publisher edits their PayPal address after requesting, the request must still record where the money was meant to go at the time it was approved.

### No ledger entry at `requested`

The funds are not debited on request. If they were, a rejection would need a reversing entry and the ledger would fill with pairs that cancel out.

Open requests are subtracted in `withdrawableBalance()` instead — see §2. The `withdrawal` ledger entry is written when the admin moves it to `processing`, the point where the money genuinely leaves.

### Chargebacks reduce an open request

The amount is frozen when the request opens, but chargebacks keep debiting the ledger. A reversal landing afterwards would otherwise leave the request promising money the publisher no longer has.

So, in the same transaction as the chargeback entry (`WithdrawalService::applyChargeback()`, called from both `ConversionProcessor::reverse()` and `forceReverse()`):

- A `requested` withdrawal is reduced by the chargeback, never below `0.000000`. `processing` and `paid` are left alone — §5, no automatic clawback.
- If the reduced amount falls under `max(publishers.min_payout, 20.00)` it is auto-rejected with an `admin_note`, keeping the amount as the record of what the chargebacks did to it. The statements it settled are unlinked, so the next monthly run re-settles them on a fresh request.
- **No ledger entry for the reduction.** `requested` was never debited; the chargeback entry is the only movement.
- `withdrawal.reduced_by_chargeback` is written to `audit_logs` with the amount before and after. Like every other entry in that table it needs an actor, so the automatic postback path records nothing — only an admin `forceReverse()` does.

`approve()` refuses outright if the amount still exceeds the publisher's `clearedBalance()`. Defense in depth: whatever else drifts, we never pay out more than has cleared.

### Admin processing

The admin queue shows, per request: publisher, amount, method, masked destination, account age, lifetime earnings, **fraud score**, reversal rate, and the last 30 days of conversions.

- **Approve** → `processing`, writes the negative ledger entry, decrements the cached balance.
- **Mark paid** → `paid` with an external `tx_reference`. Notifies the publisher.
- **Reject** → `rejected` with a mandatory reason. From `requested`, no ledger entry and the balance is untouched. From `processing`, a compensating `adjustment` referenced to the withdrawal returns the amount. Notifies the publisher with the reason.

Every transition writes to `audit_logs` with the actor, and moves the statements the withdrawal settles (§9): paid → `paid`, rejected → back to `pending` and unlinked.

### Payments are manual in v1

No PayPal Payouts API, no automated crypto sends. The admin pays externally and records the reference.

This is intentional. Automated payouts on a platform whose fraud model is still being tuned is how you get drained overnight. Automation goes in once the fraud rules have real data behind them — see [12-roadmap.md](12-roadmap.md).

---

## 5. Negative Balances

A reversal landing after the money was withdrawn produces a negative balance.

- The publisher sees it, with the reversal listed in their transactions and a plain explanation of what happened.
- Future earnings pay it down automatically. No separate collection flow.
- Withdrawals are blocked while the balance is negative.
- Negative for more than 30 days → admin alert.
- **No automatic clawback from paid withdrawals.** A human decides, in the admin panel, and it is logged.

---

## 6. Reconciliation

### Nightly drift check

```php
class ReconcileBalancesJob
{
    public function handle(): void
    {
        Publisher::chunkById(200, function ($publishers) {
            foreach ($publishers as $publisher) {
                $derived = $publisher->ledgerEntries()->sum('amount');

                if (bccomp((string) $derived, (string) $publisher->balance, 6) !== 0) {
                    Log::critical('Balance drift', [
                        'publisher_id' => $publisher->id,
                        'cached'       => $publisher->balance,
                        'derived'      => $derived,
                    ]);

                    AdminAlert::raise('ledger.drift', $publisher);
                    // Do NOT auto-correct. Drift means a bug; silently fixing it hides the bug.
                }
            }
        });
    }
}
```

`bccomp` at 6 decimal places, not `===` on floats. Comparing money with `==` is how drift alerts start firing on rows that are actually fine.

**Drift is never auto-corrected.** A cached balance that disagrees with the ledger means code wrote one without the other. Fixing the symptom nightly means never finding the cause.

### Monthly partner reconciliation

Compare what each network's own reporting says they owe us against our `conversions` sum for the period. A gap in either direction is a missed postback or a duplicate we accepted. Surfaced in the admin panel as a per-network monthly table; the comparison figure is entered manually in v1 since not every network exposes a reporting API.

---

## 7. Reporting Figures

Every money figure in either panel comes from one of two places: `ledger_entries` (balances, withdrawals) or `daily_stats` (charts, trends). Never an ad-hoc `SUM` over `conversions` in a Filament widget — that is how two pages end up showing two different numbers for the same thing.

`daily_stats` is rebuilt hourly for the current day and once nightly for the previous day, so late-arriving postbacks are picked up.

---

## 8. Tests

- Earning entry: `balance_after` equals previous balance plus amount, cached balance matches.
- Concurrent earnings for one publisher → correct final balance and a correct `balance_after` chain (run with real locking, not SQLite).
- Chargeback returns the balance to its exact pre-conversion value.
- Hold window: a conversion inside its window counts as pending, not cleared; past it, the reverse.
- **`withdrawableBalance()` drops by the requested amount the moment a request is opened**, while `clearedBalance()` does not — the exact case that would otherwise let a publisher request the same money twice.
- Withdrawal below `min_payout` → rejected with a validation error.
- Withdrawal above withdrawable → rejected.
- A second withdrawal while one is open → rejected.
- Two concurrent withdrawal requests → exactly one succeeds.
- `processing` writes exactly one negative entry; `rejected` writes none.
- Ledger immutability: `update()` and `delete()` both throw.
- Drift job detects a hand-corrupted cached balance and does not correct it.
- Withdrawal snapshots payment details, and later editing the payment method does not change the withdrawal.
- One statement per (publisher, period), keyed on `created_at` month; payout traffic excluded; a rerun leaves it unchanged and opens no second withdrawal.
- A withdrawal settles every unlinked statement up to the previous month; `paid` flips them all; `reject` unlinks them and the next run relinks.
- Below the minimum → `carried`, no withdrawal; the next run that clears the minimum settles both months.
- The backfill is idempotent and `--dry-run` writes nothing.

---

## 9. Monthly Statements

`payout_statements` is one row per publisher per calendar month — what the Payments page lists and what the credit note is issued for. A statement says what the month **earned**; the withdrawal it links to says what was **paid**. They are different rows on purpose.

| Column | Meaning |
|---|---|
| `period` | First day of the month, UTC. Unique with `publisher_id`. |
| `gross` | Σ `earning` (and `referral`) entries **created** in the month |
| `chargebacks` | Σ `chargeback` entries created in the month (≤ 0) |
| `adjustments` | Σ `adjustment` entries created in the month, excluding the compensating credit a rejected payout writes |
| `net` | `gross + chargebacks + adjustments` |
| `status` | `pending` · `carried` · `paid` |
| `due_date` | Last day of the month after the period — **NET 30** as the panel states it |
| `withdrawal_id` | The payout that settles it, once one exists |
| `note` | Why nothing was requested: the blocked reason, "below the minimum", or a rejection |

**Bucketed by `ledger_entries.created_at`, not `available_at`.** Chargebacks and adjustments have no `available_at`; the 2026-09-01 migration rewrote historical `available_at` values; and the credit note already buckets conversions by month of creation. One rule for every type, and the statement, the credit note and the "This month" card all agree. `withdrawal` entries are payout traffic, not activity, and are left out.

**Frozen once written.** `PayoutStatementService::generate()` is `firstOrCreate` on the unique key and never recomputes. A late entry for a closed month lands on the balance (and the next payout) but does not move a document a publisher may already have reconciled.

**Rollover is derived, not stored.** A month whose net is ≤ 0 or below the minimum is `carried` with no withdrawal. Nothing accumulates in the statement: the ledger already accumulates, and the next payout is the full cleared balance. When that payout opens, `settle()` links **every** unlinked statement up to the previous month, so a `carried` August and a `pending` September both point at October's withdrawal. The detail modal on the latest of them shows the part the linked statements do not explain — an entry that landed between midnight and the 00:10 run, or money credited back from a rejection — as "Carried from earlier periods". A statement's `net` therefore does not have to equal the withdrawal's `amount`; support should read that as designed, not as drift.

**Status follows the withdrawal.** `markPaid()` flips every linked statement to `paid` inside the same transaction. `reject()` unlinks them, back to `pending`, with the reason in `note`; the next run settles them again. There is no `rejected` statement — rejection belongs to the payment attempt, not to the period.

**Order on the 1st.** `withdrawals:request-monthly` writes last month's statements first, then opens withdrawals, then links. One command, so the order cannot be lost to two cron entries. `payouts:backfill-statements` is the one-time catch-up after the migration (docs/11 §4).

**Nightly check.** `payouts:reconcile-statements` (03:05, after `ledger:reconcile`) asserts the structure: a `paid` statement has a paid withdrawal and a `paid_at`; a `pending` one linked to a withdrawal is not linked to a rejected or already-paid one; a `carried` one is not linked at all; `net` is `gross + chargebacks + adjustments`; `due_date` is the end of the following month; no statement is settled by a withdrawal requested before its month began (a request made inside the month is allowed only by the backfill's `--link-through`). A statement's net differing from the withdrawal's amount is **not** a failure — that is the carry. Failures raise `statements.drift` through `AdminAlert` and are never corrected automatically.

The credit note for a withdrawal with statements covers `min(period)` to `max(period)` end of month and is due on `max(due_date)`; a withdrawal from before statements existed keeps the old "since the previous paid withdrawal, due in 30 days" derivation.
