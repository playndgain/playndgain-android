---
title: About PLAYNDGAIN
description: A rewarded offerwall built so the money path is accurate, auditable, and hard to defraud.
---

PLAYNDGAIN is a **rewarded offerwall for app and game publishers**. Your players are already playing; we let them earn your currency by completing offers from a dozen networks of demand, and we pay you for every verified action — with the arithmetic behind every cent on the table.

We are an aggregator, not an ad network. We do not create demand. We take the offer catalogues of demand partners, normalise them into one shape, and serve them through a single hosted wall that you embed in your app or site with an iframe. When a player completes an offer, the advertiser confirms it, we credit your publisher balance, and we fire a signed callback to your server so you can credit your own user.

## The money path

```
Advertiser  →  PLAYNDGAIN  →  Publisher  →  Player
(confirms      (credits       (credits
 action)        payout)        coins)
```

Four hops, and every one of them is somewhere the number can go wrong. Everything in this system exists to make that path **accurate, auditable, and hard to defraud**. That is the whole design brief.

## The premise

Publishers stopped trusting their own dashboards. Aggregators quote a rate at signup and settle on another. Conversions vanish without an entry. Reversals are netted out quietly, so the figure you are paid never matches the figure you counted.

We built the ledger first and the marketing second. Every click, rule, callback and correction is a row you can export — including the rows that cost us money.

## Why one integration instead of a dozen

A publisher who wants a broad wall has two options: integrate eight networks, each with its own API shape, its own postback format, its own signature scheme and its own idea of what a reversal looks like — or integrate one.

The aggregator absorbs that work. One iframe, one macro set, one signature scheme, one place where offers are deduplicated across the networks that carry the same campaign. The same offer on three networks shows once, at the highest payout your player is eligible for. What we add on top of a direct integration is not access to inventory; it is that the eight failure modes become one.

## The ledger is append-only

Balances are not a column somebody increments. Every credit, every reversal and every withdrawal is a row in an append-only ledger, and that ledger is the only truth about money in the system. The balance shown on a dashboard is a cached number written in the same database transaction as the entry that changed it, re-derived from the ledger every night, and alerted on if the two ever disagree.

Nothing is edited and nothing is deleted. A credit and the chargeback that cancelled it are two permanent rows and both stay visible forever. This is the only arrangement in which a disagreement about money can actually be settled, rather than argued.

Money is stored to six decimal places, never as a floating-point number. Payouts of $0.0037 are ordinary in this business and they accumulate across millions of rows.

## Nothing blocks silently

The fraud engine is a set of database rules with thresholds an administrator can see and change, evaluated before a click leaves the wall and again when a conversion arrives. Every evaluation that blocks something writes an event recording the rule that fired and the values it saw.

That is a deliberate constraint rather than a logging preference: a publisher asking *"why was my player not credited"* must always be answerable. A fraud system nobody can explain is indistinguishable from a broken one.

## What we deliberately do not do

- **No advertiser panel.** We are an aggregator. We do not sell campaigns directly and there is no advertiser side to this product.
- **No automatic approval.** Every account and every app is reviewed by a person before it can earn. This costs us signups and it is the right trade.
- **No REST catalogue API, no JS SDK, no mobile SDK.** The wall is a hosted iframe, and one delivery method that works is better than four that half-work.
- **No sub-accounts, no CSV exports, no multi-currency payouts.** All USD, all first-party.
- **No IAB TCF vendor registration.** We run our own consent gate and say so plainly rather than implying a certification we do not hold.

Each of those is a decision, not a gap. The list of things this product refuses to do is the reason the things it does do are dependable.

## The stack, briefly

One Laravel application on one server: nginx, PHP-FPM, MySQL, Redis. No Node process in production, no SSR renderer that can die quietly and drop the site out of search, no microservices to keep in sync. Every public page — this one, the documentation, the wall — is finished HTML the moment PHP stops. JavaScript is an enhancement on top of content that already exists.

The wall in particular is a list of offers rendered on a mid-range Android phone over 3G, and it is budgeted like one.

## Get in touch

Publishers start at [the documentation](/docs/introduction) — the quick start is six steps and does not require talking to anyone. Networks with inventory to supply should read [supply offers](/advertise). Everything else: [contact us](/contact).
