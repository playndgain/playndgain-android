---
title: Chargebacks & reversals
description: Reversals send a negative amount. Ignoring them means paying out rewards on revenue you never received.
---

> **Read this page before you go live.**
>
> Ignoring reversals is the single most common integration failure on this platform, and the failure mode is that **you pay out rewards on revenue you were never paid**. It does not throw an error. It does not show up in a log. It shows up in your margin, months later.

## Reversals send a negative amount

When an advertiser rejects a conversion — a refunded purchase, a fraudulent install, a duplicate — the network reverses it and we pass that on to you:

```txt
?user_id=USER_123&trans_id=OFFER19624243-Gw2CpY&currency_amount=-130.00
&payout_usd=-0.130000&status=reversed&signature=…
```

- `trans_id` is **the original transaction**, so you can find what to undo.
- `currency_amount` and `payout_usd` are **negative**.
- `status` is `reversed`.
- The signature covers the negative payout, so a reversal cannot be replayed as a credit.

## What your code must do

Deduct the currency you credited.

If the user has already spent it, your policy decides — go negative, cap at zero, or flag the account. What you must not do is ignore reversals.

```php
$amount = (float) $_GET['currency_amount'];

if ($amount < 0) {
    $user->decrement('coins', abs($amount));   // your policy on going negative
    Transaction::create([...]);                 // record it either way
    exit('ok');
}
```

Three policies, all defensible. Pick one on purpose:

| Policy | Behaviour | Suits |
|---|---|---|
| **Allow negative** | Balance goes below zero; future earnings pay it down | Long-lived accounts, real economies |
| **Cap at zero** | Deduct what is there, absorb the rest | Casual apps where a negative balance confuses users |
| **Flag** | Deduct what is there, mark the account for review | Anywhere a reversal is a fraud signal about that user |

What is not defensible is a fourth option — treating the amount as unsigned and **adding** 130 coins for a chargeback. That is the bug this page exists to prevent.

## Detecting it, not guessing it

Two independent signals arrive together. Use either; do not use neither.

```php
$isReversal = $_GET['status'] === 'reversed';
// or
$isReversal = (float) $_GET['currency_amount'] < 0;
```

`status` is explicit and readable. The sign is the one that decides the arithmetic. Branching on the sign means a currency-amount of zero can never silently produce a debit.

## Record it either way

Write a row for the reversal even when you cannot take the coins back. Two reasons, both practical:

1. Your dedupe key must stay unique. A reversal shares `trans_id` with the credit it undoes, so store the two events as separate rows keyed on `(trans_id, status)` — not one row you overwrite.
2. When a user asks why their balance dropped, the answer has to be in your database, not in ours.

```php
Transaction::create([
    'trans_id' => $_GET['trans_id'],
    'status'   => $_GET['status'],        // 'credited' or 'reversed'
    'amount'   => (float) $_GET['currency_amount'],
]);
```

A unique index on `(trans_id, status)` then dedupes retries of the credit and retries of the reversal independently. See [Idempotency](/docs/idempotency).

## Timing

Reversals typically arrive **within 30 days**. They can arrive later — the advertiser's refund window, not ours, sets the outside bound.

This is why earnings stay pending until the start of the next calendar month. A reversal that lands after the money has been paid out still debits immediately — a debt, not an adjustment.

## What happens on our side

- Your balance is decremented by the same payout that credited it.
- A chargeback entry is written to the ledger. Nothing is edited or deleted; the ledger is append-only, and the credit and the chargeback both stay visible.
- If your balance goes negative, future earnings pay it down automatically. There is no separate collection step.
- We do **not** automatically claw back from a withdrawal that has already been paid. That is a human decision, taken in the admin panel and recorded in the audit log.

Reversing an already-reversed conversion is a no-op. If our upstream network sends the same reversal twice, you receive one reversal postback — but you should still dedupe it, because delivery retries can duplicate it on the way to you.

## Test it before you need it

You do not have to wait for a real chargeback. Take the curl at the bottom of [Signature verification](/docs/signature-verification), negate the payout and the amount, recompute the signature, and fire it at your endpoint.

If the user's balance goes down, you are done. If it goes up, you have found the bug this page is about.
