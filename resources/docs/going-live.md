---
title: Going live
description: What approval means, what the reviewer is reading, and the checklist before you send real traffic.
---

Two separate approvals stand between a new account and live offers: your **publisher account**, and each **app** on it. Both are decided by a person.

## What you can do before approval

Everything except serve real offers. You can sign in, create apps, get an App ID and secret, configure a callback, turn on test mode and fire a real test conversion end to end.

While your account is `pending`, the wall renders the "Unavailable" screen instead of offers. That is not a fault to debug. It is the state you are in until somebody approves you.

So build and test the whole integration during the review. By the time you are approved, the only thing left is to turn test mode off.

## What the reviewer is reading

The description of your traffic that you gave at signup, and a look at the property you named. Specific answers get approved faster, and vague ones get a reply asking the same question again.

What they are trying to establish, in plain terms:

- **That your players are people.** Where they come from, what your product is, why they would want your currency
- **That the placement is honest.** The wall is a rewards feature your player chose to open, not something disguised or forced
- **That the reward is for the offer.** Not for the click, not for opening the wall, not for reaching the rewards screen

Answer with numbers and specifics. "Rewards app for Egyptian and Gulf users, mostly Android, 40k monthly actives, organic from TikTok, players spend coins on gift cards" is a description someone can approve. "Mobile traffic, worldwide" is not, and gets an email rather than an approval.

This is the cheapest fraud control we have, and it is the reason your payouts are not funding somebody's bot farm.

## The two attestations

At signup you confirmed two things, and they carry weight later:

- You accept the publisher agreement and the traffic policy
- Your traffic is incentivised, and you will not send bot, proxy or purchased traffic

[Traffic rules](/docs/traffic-quality) is the whole list of what voids conversions. Read it before you place the wall, not after.

## Checklist before real traffic

**Turn test mode off.** The Test Offer pays `$0.01` and is visible to every player of an app in test mode. This is the single most common thing left switched on.

**Confirm the callback handler does all four things.** Verifies the signature, dedupes on `(trans_id, status)`, branches on the sign of the amount, answers `200`. The curl at the bottom of [Verifying the signature](/docs/signature-verification) proves all four in four calls.

**Check the frame attributes on a real device.** A `sandbox` missing `allow-popups` looks fine until a player taps an offer.

**Set your currency rate deliberately.** It is the number your players see and it is awkward to change once they have a mental model of what a coin is worth. [Your payout and your rate](/docs/your-payout)

**Add a payment method.** Nothing blocks a first payout more often than a missing one, and it is discovered at month end when it is too late for that month. [Statements and payouts](/docs/statements-and-payouts)

**Check your notification address.** It is where a postback failure alert goes, and a failure you do not hear about runs for nine hours and then stops.

## After you launch

Watch three things in the first week:

| Where | What good looks like |
|---|---|
| Postback Reports | Failure rate near zero. Anything above 20% in 15 minutes alerts you anyway |
| Conversions | A reversal rate that stays near your own baseline |
| Your own database | One row per conversion, and reversals present rather than absent |

The third is the one to actually check. A reversal branch that was never exercised in testing fails quietly in production, and the only place it shows up is your margin.

## If your traffic is reviewed later

You hear from us before anything changes on your account, unless the pattern is unambiguous fraud.

A review is a conversation with evidence in it: conversion timings, device overlap, the reversal reports from our demand partners. Bring yours, which is analytics on where the wall sits, how players find it, and what changed on the day the pattern started. Most reviews end in a placement change rather than a suspension.

Cleared money is yours and is not withheld during a review of new traffic. What a review can do is hold `pending` conversions until it concludes, and stop new inventory reaching an app.
