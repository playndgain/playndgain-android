---
title: Your payout and your rate
description: Two numbers that look related and are not. One is what you earn, the other is what your player sees.
---

Two numbers sit between a completed offer and a coin in your game. They are set in different places, they do different jobs, and confusing them is the most common reason a publisher thinks their earnings are wrong.

| Number | Set where | Decides |
|---|---|---|
| **Your payout** | Per offer, and shown on every conversion | What **you** earn |
| **Currency rate** | Per app, by you | What **your player** is credited |

Changing your currency rate does not change your earnings by a cent.

## Your payout

Every offer carries a payout, and every conversion records the payout it earned you. That figure is yours: it is what lands in your ledger, what your statement totals, and what you are paid.

`{payout_usd}` on the callback is that figure. There is no adjustment applied to it afterwards, no platform fee taken at payout time, and no rounding in our favour. What arrives on the callback is what lands in the ledger and what leaves in the payout.

Money is held to six decimal places throughout. Payouts of a third of a cent are ordinary in this business and they accumulate.

Payouts are not fixed. The same offer can be worth different amounts in different countries, and an offer's payout can change or the offer can disappear entirely, because inventory belongs to the advertiser rather than to us. Every conversion records what it earned at the time it converted, which is why the ledger and not a rate card is the answer to what you earned.

## Currency rate

How many units of your currency one dollar buys. Set per app in **Apps → Currency**.

```txt
your payout (USD)  ×  currency_rate  =  currency_amount
       0.091000    ×       1000      =        91.00
```

We send both figures on every callback, so your handler never multiplies anything:

- `{payout_usd}` is yours. Do not credit the player with it. It is dollars
- `{currency_amount}` is the player's. Credit this one

That split is why changing your rate in the dashboard needs no deploy on your side, and why a rate change never retroactively alters what you earned.

## Picking a rate

The rate is a design decision about your economy, not a financial one. It decides whether a reward reads as "350 Coins" or "3 Coins", and whether your shop prices have to be four digits.

Two things worth knowing before you choose:

**Round numbers read as fair.** A rate of 1000 turns most conversions into three-figure rewards, which players read as substantial without you inflating anything.

**Changing it later is awkward.** Existing balances are in your currency and were earned at the old rate. Nothing on our side breaks, but your players have a mental model of what a coin is worth and it does not survive a tenfold change.

## The arithmetic

One conversion that earns you $0.35, at a rate of 1000:

```txt
Your payout                  $0.350000     ← {payout_usd}, and your ledger entry
  × 1000 currency rate
Player is credited            350.00       ← {currency_amount}, and your increment
```

One multiplication, and we do it for you. If your own reports disagree with ours, the cause is nearly always that you summed `{payout_usd}` where you meant `{currency_amount}`, or the reverse.

## Where to see it

Per-conversion payouts are in **Conversions**, on every row. Per-offer payouts are on the **Offers** page, in the amount you earn. Per-month totals are on your statements.

Test conversions carry a payout but never write a ledger entry, so they appear in reports marked as tests and never in your earnings.
