---
title: Traffic quality
description: What we measure, what gets flagged, and what a review actually involves.
---

Advertisers pay for users who do something. Networks pass their quality standards to us and we pass ours to you. This page is what those standards are, so nothing on it is a surprise.

## What we measure

Every click is scored before it leaves the wall, and every conversion is scored on arrival. The signals are ordinary ones:

| Signal | Why it matters |
|---|---|
| Click-to-conversion time | A conversion seconds after the click did not involve installing an app |
| Conversion rate per app | A rate far above the platform norm is the strongest single indicator of incentivised fraud |
| Repeat device or IP | Many end user IDs behind one device is a multi-accounting pattern |
| Datacentre and proxy IPs | Real users are not on hosting ranges |
| Country mismatch | The click geo and the conversion geo disagreeing means a VPN or a rewritten parameter |
| Reversal rate | Advertisers rejecting your conversions is their judgement of your traffic |

## What a flag does

Two outcomes, and they are different.

**Flag** — the conversion is held as `pending` and goes into a review queue. No ledger entry is written until it is resolved. You are not notified per conversion; you see it in your Conversions report with a `pending` status.

**Block** — the conversion is `rejected` on arrival. No ledger entry, no postback, terminal. Blocking rules exist for patterns with no legitimate explanation, not for borderline traffic.

Clicks can also be blocked before they reach the network. A blocked click still writes a row with its reason, visible in your Clicks report.

## The thresholds we alert on

| Signal | Threshold |
|---|---|
| Postback failure rate for one app | over 20% in 15 minutes |
| Reversal rate | reviewed when it runs materially above your own historical rate |
| Negative balance | an alert after 30 days |

## What we expect from you

- **Stable, unique user identifiers.** One `userid` per real person. See [Core concepts](/docs/core-concepts).
- **Honest placement.** The wall is presented as a rewards feature, not disguised as something else.
- **No incentive beyond the offer.** Your users are rewarded for completing an offer. Rewarding them for clicking, or for opening the wall, produces traffic advertisers reject and reverses land on you.
- **Real users.** Everything on [Prohibited traffic](/docs/prohibited-traffic).

## If your traffic is reviewed

You will hear from us before anything changes on your account, unless the pattern is unambiguous fraud.

A review is a conversation with evidence in it — conversion timings, device overlap, the reversal reports from the networks. Bring your own: analytics on where the wall is placed, how users find it, and what changed on the day the pattern started. Most reviews end with a placement change, not with a suspension.

## What we do not do

We do not withhold cleared earnings during a review of new traffic. Cleared money is yours. What a review can do is hold `pending` conversions until it concludes, and stop new inventory reaching an app.
