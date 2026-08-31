---
title: Supply offers to AdVault
description: What a network needs to expose for us to carry its inventory, and what we send back.
---

This page is for **advertising networks with inventory to place**, not for advertisers with a campaign to run. We are an aggregator: we carry other networks' offers on a hosted offerwall and pay our publishers a share of what you pay us. There is no advertiser panel and no self-serve campaign upload.

If you run offers and want them in front of our publishers' users, this is the shortest description of what that takes.

## What we need from you

**1 · A catalogue endpoint.** An HTTP endpoint we can poll on a schedule that returns your live offers as JSON. What we read from each one:

| Field | Required | Notes |
|---|---|---|
| A stable offer ID | Yes | We key on `(network, id)` forever. It must not be reused for a different campaign |
| Name and description | Yes | Shown to the user as-is, so it has to read like a task |
| Payout, in USD | Yes | Decimal. We store six places and do not round on the way in |
| Tracking URL with a subid macro | Yes | See below — this is the part that most often needs a change |
| Country targeting | Yes | ISO-2 codes. An offer with no targeting is treated as worldwide |
| Device / OS targeting | Yes | At minimum: mobile, desktop, and the mobile platform |
| Icon URL | Strongly preferred | A wall of missing icons reads as a dead product |
| Completion requirements | Strongly preferred | The steps the user actually has to finish, in plain words |

**2 · A subid macro in the tracking URL.** We generate a UUID per click and substitute it into your URL. That identifier is the join key for the whole conversion path — it is what lets a postback three days later resolve back to one click by one user on one app. A network that cannot carry an opaque click identifier through to the postback cannot be integrated, and this is the single most common blocker.

**3 · A server-to-server postback on conversion.** Fired to an endpoint we give you, carrying at least the subid we sent, your transaction ID, the payout, and the status. Your transaction ID must be **stable and unique** — we deduplicate on it and drop repeats, so retrying is safe and encouraged.

**4 · Reversals on the same channel.** When an advertiser rejects a conversion, tell us, referencing the original transaction. We pass reversals straight through to the publisher as a negative amount, and a network that silently absorbs rejections instead of reporting them makes our numbers wrong rather than convenient.

**5 · Authentication we can verify.** A signature over the postback payload, **and** a source IP range we can allowlist. Both, not either. We verify both before a row is written.

## What you get back

- **Traffic from reviewed publishers only.** Every account and every app is approved by a person before it serves an offer. There is no self-serve path onto the wall.
- **Fraud filtering before the click, not after the invoice.** Rules run before the redirect and again on the conversion — duplicate conversions, one conversion per user per offer, datacentre and VPN addresses, geo mismatches against your own targeting, click-to-conversion velocity, impossible completion speed, per-app and per-user caps. Every block is recorded with the rule and its inputs.
- **Targeting we actually respect.** Offers are filtered by country and device before they are rendered, plus per-publisher blacklists. Users who already completed an offer never see it again.
- **Traffic you can audit.** Ask about any click and we can tell you its country, its device, the app it came from and the rules it passed.

## What we do not do

We do not rebroker your offers to other aggregators. We do not run incentivised *click* rewards — our publishers reward completion and nothing else, and that is contractual, not aspirational. We do not accept catalogue feeds by spreadsheet or by email.

## Getting started

Send us the API documentation and a test credential. We build against a sandbox where one exists, run a live catalogue sync into a disabled state first, verify a real conversion and a real reversal end to end, and only then enable the network for publishers.

Every network on the platform starts disabled and is switched on by an administrator pasting credentials, so nothing goes live by accident.

[Contact us about supplying offers →](/contact?topic=supply)
