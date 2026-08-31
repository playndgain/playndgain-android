---
title: Payout terms
description: Revenue share, the calendar-month hold, the minimum, and what a negative balance means.
---

## Revenue share

Your share of the advertiser payout, applied before anything reaches your balance. The default is **70%**, set per publisher and visible on your account.

```txt
Advertiser payout   $0.130000
Revenue share       × 70%
                    ─────────
Your payout         $0.091000
```

`{payout_usd}` on the postback is always **your** figure, never the gross. There is no second deduction later.

## The hold

Approved earnings are pending until the **start of the next calendar month**, then become withdrawable. A conversion on 15 January clears at 00:00 on 1 February; a conversion on the 1st waits until the following month.

A reversal that arrives while the earning is still pending debits immediately. The available balance can go negative; future earnings pay it down.

Your dashboard shows three figures, and they are different things:

| Figure | Meaning |
|---|---|
| Pending | Approved, inside the hold |
| Available | Cleared, withdrawable now |
| Balance | Both, minus anything already withdrawn |

Every conversion row shows the exact date it clears.

## The minimum

**$20.** Requests below it are not accepted; the balance simply carries.

## Requesting a withdrawal

Dashboard → **Withdrawals** → **Request**. It moves from your available balance into a pending request, is reviewed, and is paid to the payment method on your account.

A request under review can be cancelled by you and the money returns to available. Once it is marked paid it is final.

Add a payment method before you need one — a first withdrawal blocked on a missing method is the most common avoidable delay.

## Reversals after the hold clears

The money may already be withdrawn when a reversal lands. In that case:

- Your ledger goes negative, and so does your balance.
- You see the negative balance and the conversion that caused it in your transactions view.
- **Future earnings pay it down automatically.** There is no separate collection step and no invoice.
- A negative balance older than 30 days raises an alert on our side and someone will get in touch.
- We do **not** automatically claw back from a withdrawal that has already been paid. That is a human decision, taken in the admin panel and recorded in the audit log.

## The ledger is append-only

Nothing is edited and nothing is deleted. A credit and its chargeback are two entries and both stay visible forever. When a figure looks wrong, the entries that produced it are all there — which is the only way a disagreement about money ever gets settled.

Balances are reconciled against the ledger nightly. A cached balance that disagrees with the sum of its entries is a bug we are told about, not something quietly corrected.

## Taxes and fees

Withdrawal fees, where the payment method charges one, are shown on the request before you confirm it. Taxes are yours — we do not withhold, and we do not issue tax documents in v1.

## Currency

Everything on our side is USD. Your users are credited in your own currency at the rate on the app. We never convert your earnings into anything else, and the rate on the app has no effect on what you are paid — only on what your users see. See [Core concepts](/docs/core-concepts).
