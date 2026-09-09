---
title: Traffic rules
description: What we measure, the list of what voids conversions, and what a review actually involves.
---

Advertisers pay for players who do something. Our demand partners pass their quality standards to us and we pass ours to you. This page is those standards written down, so nothing on it arrives as a surprise.

## What we measure

Every click is scored before it leaves the wall and every conversion is scored on arrival. The signals are ordinary ones:

| Signal | Why it matters |
|---|---|
| Click-to-conversion time | A conversion seconds after the click did not involve installing anything |
| Conversion rate per app | A rate far above the platform norm is the single strongest indicator of incentivised fraud |
| Repeat device or IP | Many player IDs behind one device is a multi-accounting pattern |
| Datacentre and proxy IPs | Real players are not on hosting ranges |
| Country mismatch | Click geo and conversion geo disagreeing means a VPN or a rewritten parameter |
| Reversal rate | Advertisers rejecting your conversions is their judgement of your traffic |

## Flagged, or blocked

Two outcomes, and they are different.

**Flagged.** The conversion is held as `pending` and enters a review queue. No ledger entry until it resolves. You are not notified per conversion. You see it in Conversions with a `pending` status.

**Blocked.** The conversion is `rejected` on arrival. No ledger entry, no postback, terminal. Blocking rules exist for patterns with no legitimate explanation, not for borderline traffic.

Clicks can also be blocked before they reach the partner. A blocked click still writes a row with its reason, visible in your Clicks report.

## Never

Everything here voids the conversions it produced, whether or not it was deliberate. If any of it describes a placement you are planning, ask first.

**Bots, emulators and automation.** Anything that completes an offer without a person.

**Incentivised clicks.** Rewarding a player for tapping an offer, opening the wall, or reaching the rewards screen. The reward is for completing the offer and for nothing else.

**Multi-accounting.** One person behind many `userid` values. Usually your own account system's problem before it is ours.

**Fake or shared identifiers.** A constant `userid`, sequential test IDs in production, or one identifier shared by a group.

**Misrepresenting the offer.** Telling players an offer pays more than it does, or that a subscription is free when the advertiser's terms say otherwise.

**Auto-redirects and pops.** The wall opened without a deliberate action, in a pop-under, or as a forced interstitial.

**Hidden or disguised placement.** A zero-opacity iframe, a wall behind a fake close button, or a wall presented as something other than a rewards feature.

**Proxy, VPN and datacentre traffic** presented as organic. If a meaningful share of your players are on hosting ranges, tell us why before you launch.

**Adult, hate, weapons, illegal and infringing content.** Whatever the traffic quality, advertisers do not accept the placement.

**Bought or resold traffic**, from click farms, paid-to-click networks, or another offerwall.

## Ask first

None of these is automatically refused. All of them are refused if we find out afterwards.

- **Cashback and paid-to-click products**, where the reward is the product rather than a feature of one
- **Kids' apps.** Age-gating and consent obligations are stricter than this platform is built for
- **Sweepstakes and lotteries**, where the reward is an entry rather than currency
- **Reselling the wall** to third-party publishers under your own account

## What we expect

- **Stable, unique identifiers.** One `userid` per real person. [Core concepts](/docs/core-concepts)
- **Honest placement.** The wall is a rewards feature a player chose to open
- **No incentive beyond the offer.** Rewarding the click produces traffic advertisers reject, and the reversals land on you

## What happens

| Finding | Consequence |
|---|---|
| Isolated bad conversions | Rejected or reversed, balance adjusted |
| A pattern in one app | The app is paused pending review |
| Deliberate fraud | Account terminated, unpaid balance forfeited, our demand partners informed |

Reversals for prohibited traffic arrive exactly like any other reversal: a negative amount on the original `trans_id`. If your handler ignores negative amounts you will already have paid your players for traffic nobody is paying you for. [Reversals](/docs/chargebacks-reversals)

## Thresholds we alert on

| Signal | Threshold |
|---|---|
| Postback failure rate for one app | over 20% in 15 minutes |
| Reversal rate | reviewed when it runs materially above your own historical rate |
| Negative balance | an alert after 30 days |

## If your traffic is reviewed

You hear from us before anything changes on your account, unless the pattern is unambiguous fraud.

A review is a conversation with evidence in it: conversion timings, device overlap, the reversal reports from our partners. Bring your own, which is analytics on where the wall sits, how players find it, and what changed on the day the pattern started. Most reviews end with a placement change rather than a suspension.

We do not withhold cleared earnings during a review of new traffic. Cleared money is yours. What a review can do is hold `pending` conversions until it concludes and stop new inventory reaching an app.

## Reporting a problem yourself

If you find a hole in your own product, a leaked test account, a bot ring, a partner sending you traffic you did not vet, tell us before the reversals do.

Publishers who report a problem get help fixing it and keep their account. Publishers who are found out do not.
