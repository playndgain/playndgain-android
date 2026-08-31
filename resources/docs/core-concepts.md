---
title: Core concepts
description: App, end user, click, conversion, postback — the six words the rest of the docs assume.
---

Six words. The rest of the documentation assumes them.

## App

One integration. An App ID (public, goes in the iframe URL) and a secret key (private, signs postbacks).

An app carries its own currency rate, callback URL, theme, country blocks and test-mode toggle. It has one of four statuses:

| Status | Meaning |
|---|---|
| `pending` | Created, not yet approved. The wall renders an "Unavailable" screen. |
| `active` | Serving offers. |
| `paused` | You paused it. Offers stop; existing conversions still settle and still post back. |
| `rejected` | Not approved. Contact support. |

One app per surface you monetise — see [Multiple placements](/docs/multiple-placements).

## End user

Your user, identified by the `userid` you put in the iframe URL. We never learn who they are, and we never issue our own user ID.

The identifier is opaque to us and echoed back verbatim as `{user_id}` on every postback. Maximum 191 characters.

**It must be stable for the lifetime of the account.** Rewards attach to whatever string was in the URL at click time. A conversion can arrive days after the click; if the identifier changed in between, the reward lands on an account that did not earn it.

## Click

A user tapping an offer. We write a row — IP, country, device, OS, browser, referrer, fraud score — then redirect to the network's tracking URL with a single-use click ID.

The row is written **even when the click is blocked** by a fraud rule, because a blocked click that produced no record is a support question nobody can answer.

## Conversion

The user did what the offer asked and the advertiser confirmed it. A conversion is money: it writes a ledger entry and increments your balance.

Four statuses — [Conversion statuses](/docs/conversion-statuses) covers what moves between them and when.

## Postback

An HTTP call. Two different things share the name:

- **Inbound** — a network telling us a conversion happened. Not your concern.
- **Outbound** — us telling **you**, at the callback URL on your app. This is the one you build.

Outbound postbacks carry macros, are signed, are retried on failure, and are logged per attempt with the response body your server returned.

## Currency rate

How many units of your currency one US dollar buys. Set per app.

```txt
your payout (USD)  ×  currency_rate  =  currency_amount
       0.091000    ×       1000      =        91.00
```

We send you both figures. `{payout_usd}` is what you earned; `{currency_amount}` is what to credit the user. Credit the user in `{currency_amount}` and you never have to reimplement the rate.

## Balance, hold, and payout

Approved conversions credit your balance immediately but stay **pending** until the start of the next calendar month. After the hold clears they become withdrawable.

The hold exists because reversals exist. An advertiser rejecting a conversion after you have already withdrawn the money is a debt, not an adjustment. [Payout terms](/docs/payout-terms) has the full mechanics.
