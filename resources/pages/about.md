---
title: About AdVault
description: An offerwall aggregator built so the money path is accurate, auditable, and hard to defraud.
---

AdVault is an **offerwall aggregator**. It is not an ad network and it does not create its own demand.

What it does is take the offer catalogues of a dozen advertising networks, normalise them into one shape, and serve them through a single hosted offerwall that a publisher embeds in their app or site with an iframe. When a user completes an offer, the network tells us, we take our cut, and we fire a signed callback to the publisher so they can credit their own user.

## The money path

```
Advertiser  →  Partner network  →  AdVault  →  Publisher  →  End user
               (pays us $X)        (keeps      (credits
                                    30%)        coins)
```

Five hops, and every one of them is somewhere the number can go wrong. Everything in this system exists to make that path **accurate, auditable, and hard to defraud**. That is the whole design brief.

## Why one integration instead of a dozen

A publisher who wants a broad offerwall has two options: integrate eight networks, each with its own API shape, its own postback format, its own signature scheme and its own idea of what a reversal looks like — or integrate one.

The aggregator absorbs that work. One iframe, one macro set, one signature scheme, one place where offers are deduplicated across the networks that carry the same campaign. What we add on top of a direct integration is not access to inventory; it is that the eight failure modes become one.

## The ledger is append-only

Balances are not a column somebody increments. Every credit, every reversal and every withdrawal is a row in an append-only ledger, and that ledger is the only truth about money in the system. The balance shown on a dashboard is a cached number written in the same database transaction as the entry that changed it, re-derived from the ledger every night, and alerted on if the two ever disagree.

Nothing is edited and nothing is deleted. A credit and the chargeback that cancelled it are two permanent rows and both stay visible forever. This is the only arrangement in which a disagreement about money can actually be settled, rather than argued.

Money is stored to six decimal places, never as a floating-point number. Payouts of $0.0037 are ordinary in this business and they accumulate across millions of rows.

## Nothing blocks silently

The fraud engine is a set of database rules with thresholds an administrator can see and change, evaluated before a click leaves the wall and again when a conversion arrives. Every evaluation that blocks something writes an event recording the rule that fired and the values it saw.

That is a deliberate constraint rather than a logging preference: a publisher asking *"why was my user not credited"* must always be answerable. A fraud system nobody can explain is indistinguishable from a broken one.

## What we deliberately do not do

- **No advertiser panel.** We are an aggregator. We do not sell campaigns directly and there is no advertiser side to this product.
- **No automatic approval.** Every account and every app is reviewed by a person before it can earn. This costs us signups and it is the right trade.
- **No REST catalogue API, no JS SDK, no mobile SDK.** The offerwall is a hosted iframe, and one delivery method that works is better than four that half-work.
- **No sub-accounts, no CSV exports, no multi-currency payouts.** All USD, all first-party.
- **No IAB TCF vendor registration.** We run our own consent gate and say so plainly rather than implying a certification we do not hold.

Each of those is a decision, not a gap. The list of things this product refuses to do is the reason the things it does do are dependable.

## The stack, briefly

One Laravel application on one server: nginx, PHP-FPM, MySQL, Redis. No Node process in production, no SSR renderer that can die quietly and drop the site out of search, no microservices to keep in sync. Every public page — this one, the documentation, the offerwall — is finished HTML the moment PHP stops. JavaScript is an enhancement on top of content that already exists.

The offerwall in particular is a list of cards rendered on a mid-range Android phone over 3G, and it is budgeted like one.

## Get in touch

Publishers start at [the documentation](/docs/introduction) — the quick start is five steps and does not require talking to anyone. Networks with inventory to supply should read [advertise](/advertise). Everything else: [contact us](/contact).
