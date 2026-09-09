---
title: Conversion statuses
description: The four states a conversion can be in, which of them pay, and why your handler only ever sees two.
---

A conversion is in exactly one of four states.

| Status | Money moved | Postback sent | Meaning |
|---|---|---|---|
| `pending` | No | No | Held for review. A fraud rule flagged it, or the partner sent it as pending |
| `approved` | Yes | Yes | Cleared. A ledger entry was written and your balance moved |
| `reversed` | Yes, negative | Yes | The advertiser rejected it after it was credited |
| `rejected` | No | No | Blocked on arrival. No ledger entry, no postback, ever |

**Only `approved` writes a ledger entry.** Only `approved` and `reversed` produce a postback.

## What sends a conversion where

```txt
partner postback arrives
        │
        ├── fails a blocking fraud rule ──────────►  rejected
        │
        ├── fails a flagging fraud rule ──────────►  pending  ──► approved
        │                                                      └─► rejected
        ├── partner says "pending" ───────────────►  pending  ──► approved
        │
        └── clean ────────────────────────────────►  approved ──► reversed
```

`pending` is not a waiting room with a timer on it. It is a review queue a person works through. Most conversions never enter it.

`rejected` is terminal. `approved` is not: it can still become `reversed` for as long as the advertiser's refund window runs, typically 30 days and occasionally longer.

## Your handler only sees two values

The `{status}` macro carries two values, and they are not these four:

| Conversion status | `{status}` on the postback |
|---|---|
| `approved` | `credited` |
| `reversed` | `reversed` |
| `pending` | no postback |
| `rejected` | no postback |

So `credited` or `reversed`, and nothing else, ever. Use `status` for your audit trail and branch on the **sign of the amount** for the arithmetic. [Reversals](/docs/chargebacks-reversals) explains why the sign is the safer branch.

## Two different words called pending

A conversion with status `pending` is under review and has moved no money.

Money in your **pending balance** has moved, is yours, and is waiting out the calendar-month hold. It came from a conversion that is `approved`.

They are unrelated, and the dashboard names them separately for exactly this reason. [Statements and payouts](/docs/statements-and-payouts) covers the hold.

## What each one asks of you

| Status | Your side |
|---|---|
| `pending` | Nothing. You are not told about it |
| `approved` | Credit the player |
| `reversed` | Deduct what you credited |
| `rejected` | Nothing. You are not told about it |

When a player swears they completed an offer and nothing arrived, check **Conversions** before you check Postback Reports. A `pending` or `rejected` conversion produces no postback at all, and no amount of debugging your endpoint will find one.
