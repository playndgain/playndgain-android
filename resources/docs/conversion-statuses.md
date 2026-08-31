---
title: Conversion statuses
description: The four states a conversion can be in, and which of them pay.
---

A conversion is in exactly one of four states.

| Status | Money moved | Postback sent | Meaning |
|---|---|---|---|
| `pending` | No | No | Held for review — a fraud rule flagged it, or the network sent it as pending |
| `approved` | Yes | Yes | Cleared. A ledger entry was written and your balance went up |
| `reversed` | Yes, negative | Yes | The advertiser rejected it after it was credited |
| `rejected` | No | No | Blocked on arrival. No ledger entry, no postback, ever |

**Only `approved` writes a ledger entry.** Only `approved` and `reversed` produce a postback.

## What sends you where

```txt
network postback arrives
        │
        ├── fails a blocking fraud rule ──────────►  rejected
        │
        ├── fails a flagging fraud rule ──────────►  pending  ──► approved
        │                                                      └─► rejected
        ├── network says "pending" ───────────────►  pending  ──► approved
        │
        └── clean ────────────────────────────────►  approved ──► reversed
```

`pending` is not a waiting room with a timer. It is a review queue a human works through. Most conversions never enter it.

`rejected` is terminal. `approved` can still become `reversed` — for as long as the advertiser's refund window runs, which is typically 30 days and occasionally longer.

## The postback status is not this status

The `{status}` macro carries only two values:

| Conversion status | `{status}` on the postback |
|---|---|
| `approved` | `credited` |
| `reversed` | `reversed` |
| `pending` | no postback |
| `rejected` | no postback |

Your handler only ever sees `credited` or `reversed`. Branch on the sign of the amount for the arithmetic, and use `status` for the audit trail — see [Chargebacks & reversals](/docs/chargebacks-reversals).

## Where you see them

**Conversions** in the dashboard, filterable by status. Each row shows the offer, the end user, the payout, the status and — for approved conversions — the date the hold clears.

A conversion that is `approved` but not yet cleared shows in your **pending balance**, not your withdrawable balance. That is the calendar-month hold and it is a different thing from the `pending` status, which is a review state. The dashboard names them separately for exactly this reason.

## What you should do with each

| Status | Your side |
|---|---|
| `pending` | Nothing. You are not told about it |
| `approved` | Credit the user |
| `reversed` | Deduct what you credited |
| `rejected` | Nothing. You are not told about it |

If a user swears they completed an offer and nothing arrived, check Conversions before checking Postback Reports. A `pending` or `rejected` conversion produces no postback at all, and no amount of debugging your endpoint will find it.
