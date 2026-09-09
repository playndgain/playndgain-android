---
title: Core concepts
description: App, end user, click, conversion, postback, currency rate. Every other page assumes these.
---

Six words, used precisely. The rest of the documentation assumes them.

## App

One integration. An **App ID**, which is public and goes in the wall URL, and a **secret key**, which is private and signs postbacks.

An app owns its own currency rate, callback URL, theme, country blocks and test-mode toggle. It has one of four statuses:

| Status | Meaning |
|---|---|
| `pending` | Created, not yet approved. The wall renders an "Unavailable" screen |
| `active` | Serving offers |
| `paused` | You paused it. Offers stop. Conversions already in flight still settle and still post back |
| `rejected` | Not approved. Contact support |

One app per surface you monetise. [One app or several](/docs/multiple-placements) is the decision in full.

## End user

Your player, identified by the `userid` you put in the wall URL. We never learn who they are and we never issue an identifier of our own.

The string is opaque to us and comes back verbatim as `{user_id}` on every postback. Maximum 191 characters.

**It must be stable for the lifetime of the account.** Rewards attach to whatever string was in the URL at click time. A conversion can arrive days later, and if the identifier changed in between, the coins land on somebody who did not earn them.

## Click

A player tapping an offer. We write a row (IP, country, device, OS, browser, referrer, fraud score) and redirect to the demand partner's tracking URL with a single-use click ID.

The row is written **even when a fraud rule blocks the click**, because a blocked click with no record is a support question nobody can answer.

## Conversion

The player did what the offer asked and the advertiser confirmed it. A conversion is money: it writes a ledger entry and moves your balance.

It has four states of its own, which are not the same thing as the two values your endpoint sees. [Conversion statuses](/docs/conversion-statuses) has both tables.

## Postback

An HTTP call. Two different things share the name, and only one of them is yours:

- **Inbound.** A demand partner telling us a conversion happened. Not your concern.
- **Outbound.** Us calling the callback URL on your app. This is the one you build.

Outbound postbacks carry macros, are signed, are retried on failure, and are logged per attempt with the response your server returned.

## Currency rate

How many units of your currency one dollar buys. Set per app.

```txt
your payout (USD)  ×  currency_rate  =  currency_amount
       0.091000    ×       1000      =        91.00
```

You are sent both. `{payout_usd}` is what **you** earned. `{currency_amount}` is what to credit the **player**. Credit the player in `{currency_amount}` and the rate never appears in your code, which means changing it in the dashboard never requires a deploy.

The rate has no effect on what you are paid. It only decides what the number in your game looks like. [Your payout and your rate](/docs/your-payout) separates the two properly.

## Two meanings of "pending"

The word does two jobs on this platform and confusing them wastes an afternoon.

| Which one | What it means |
|---|---|
| Conversion status `pending` | Held for review. No money has moved, no postback was sent |
| **Pending balance** | Money that moved and is yours, waiting out the hold before it can be paid |

A conversion can be approved, have paid you, have credited your player, and still sit in your pending balance until the month turns. The dashboard names them separately for this reason. [Statements and payouts](/docs/statements-and-payouts) covers the hold.
