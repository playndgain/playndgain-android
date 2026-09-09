---
title: Reversals
description: A rejected conversion arrives as a negative amount. Ignoring it means paying players out of your own pocket.
---

> **Read this page before you go live.**
>
> Ignoring reversals is the most common integration failure on this platform, and the failure mode is that **you keep paying players for revenue you were never paid**. Nothing throws. Nothing appears in a log. It shows up in your margin, months later.

## What a reversal is

An advertiser rejects a conversion: a refunded purchase, a fraudulent install, a duplicate. The demand partner reverses it, and we pass that straight through to you.

```txt
?user_id=USER_123&trans_id=OFFER19624243-Gw2CpY&currency_amount=-130.00
&payout_usd=-0.130000&status=reversed&signature=…
```

Four things are true of every reversal:

- `trans_id` is **the original transaction**, so you can find what to undo
- `currency_amount` and `payout_usd` are **negative**
- `status` is `reversed`
- The signature covers the negative payout, so a reversal cannot be replayed as a credit

## What your code must do

Take back the currency you credited.

```php
$amount = (float) $_GET['currency_amount'];

if ($amount < 0) {
    $player->decrement('coins', abs($amount));   // your policy on going below zero
    Transaction::create([...]);                   // record it either way
    exit('ok');
}
```

If the player already spent the coins, your economy decides what happens. Three policies, all defensible. Pick one deliberately:

| Policy | Behaviour | Suits |
|---|---|---|
| **Allow negative** | The balance goes below zero and future earnings pay it down | Long-lived accounts, real economies |
| **Cap at zero** | Deduct what is there, absorb the rest | Casual games where a negative balance just confuses people |
| **Flag** | Deduct what is there, mark the account for review | Anywhere a reversal is also a fraud signal about that player |

The fourth option is the bug this page exists to prevent: treating the amount as unsigned and **adding** 130 coins for a chargeback.

## Detect it, do not guess it

Two independent signals arrive together. Use either. Do not use neither.

```php
$isReversal = $_GET['status'] === 'reversed';
// or
$isReversal = (float) $_GET['currency_amount'] < 0;
```

`status` is the readable one. The sign is the one that decides the arithmetic, and branching on the sign means an amount of zero can never silently produce a debit.

## Record it even when you cannot claw it back

Write a row for the reversal whether or not the coins were there to take. Two practical reasons:

1. **Your dedupe key stays correct.** A reversal shares its `trans_id` with the credit it undoes, so store them as two rows keyed on `(trans_id, status)` rather than one row you overwrite.
2. **The player will ask.** When someone's balance drops, the answer has to be in your database, not in ours.

```php
Transaction::create([
    'trans_id' => $_GET['trans_id'],
    'status'   => $_GET['status'],        // 'credited' or 'reversed'
    'amount'   => (float) $_GET['currency_amount'],
]);
```

A unique index on `(trans_id, status)` then dedupes retries of the credit and retries of the reversal independently. [Retries and duplicates](/docs/retries-and-failure) has the full pattern.

## When they arrive

Usually **within 30 days**. They can arrive later, because the advertiser's refund window sets the bound, not ours.

That window is exactly why your earnings sit in a hold until the month turns. A reversal that lands after the money has been paid out still debits immediately: it is a debt, not an adjustment. [Statements and payouts](/docs/statements-and-payouts) covers what a negative balance does.

## What happens on our side

- Your balance is decremented by the same payout that credited it
- A chargeback entry is appended to the ledger. Nothing is edited and nothing is deleted, so the credit and the chargeback both stay visible forever
- A negative balance is paid down automatically by future earnings. There is no separate collection step and no invoice
- We do **not** automatically claw back from a withdrawal already paid. That is a human decision, taken in the admin panel and written to the audit log

Reversing an already-reversed conversion is a no-op on our side. You still dedupe it, because delivery retries can duplicate it on the way to you.

## Test it before you need it

You do not have to wait for a real chargeback. Take the curl at the bottom of [Verifying the signature](/docs/signature-verification), negate the payout and the amount, recompute the signature, and fire it at your endpoint.

The player's balance goes down: you are finished. It goes up: you have just found the bug this page is about, on a Tuesday afternoon rather than in a quarterly reconciliation.
