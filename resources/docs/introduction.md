---
title: Introduction
description: What AdVault is, how the money flows, and what you have to build.
---

AdVault aggregates offers from a dozen advertising networks and serves them through one hosted offerwall. You embed an iframe, we show your users offers, they complete them, and we pay you a share of what the advertiser paid us.

You build two things: an iframe and a callback endpoint. Everything else is ours.

## How the money flows

```txt
Your user
   │  taps an offer on the wall
   ▼
AdVault  ──── click  ───►  Network  ────►  Advertiser
   ▲                                          │
   │                              conversion  │
   │  ◄──── postback ──── Network  ◄──────────┘
   │
   │  credits your balance, then calls your endpoint
   ▼
Your server  ──►  credits the user in your currency
```

1. A user taps an offer inside the wall. We write a click row and redirect them to the network's tracking URL.
2. The user completes the offer. The advertiser tells the network; the network sends us a postback.
3. We check it for fraud, record a conversion, and write a ledger entry for your share.
4. We call **your** callback URL with the transaction details and a signature.
5. Your server verifies the signature and credits the user in your own currency.

Step 5 is the only code you write that touches money. Get the signature check, the deduplication, and the negative-amount branch right and the integration is finished.

## What you get paid

Your revenue share is applied to the advertiser payout before anything reaches you. The default is **70%**, set per publisher.

```txt
Advertiser payout   $0.130000
Revenue share       × 70%
                    ─────────
Your payout         $0.091000
```

Your users are credited in **your** currency, not dollars. You set the rate — if `1000 Coins = $1`, that $0.091 conversion credits the user **91 Coins**. The USD figure never appears on the wall.

## When you get paid

Earnings stay pending until the **start of the next calendar month**, then become withdrawable. A reversal after you have been paid still debits immediately — the balance can go negative, and later earnings pay it down.

The minimum withdrawal is **$20**. Both figures are visible per conversion in your dashboard, with the exact date each one clears.

## What we do not claim

We are **not** a registered IAB TCF vendor. If you send us a TC string we log it and pass it to any network that accepts one, but nothing here should be read as TCF compliance. See [Countries & devices](/docs/countries-and-devices) for what we collect and what the consent gate does.

## Next

[Quick start](/docs/quick-start) puts a working integration in front of you in about ten minutes. If you want the vocabulary first, read [Core concepts](/docs/core-concepts).
