---
title: Prohibited traffic
description: The list. Everything here voids the conversions it produced.
---

Everything on this list voids the conversions it produced, whether or not it was deliberate. If any of it describes a placement you are planning, ask first.

## Never

**Bots, emulators and automation.** Anything that completes an offer without a person.

**Incentivised clicks.** Rewarding a user for tapping an offer, opening the wall, or reaching the rewards screen. The reward is for completing the offer and nothing else.

**Multi-accounting.** One person behind many `userid` values. This is usually your own account system's problem before it is ours.

**Fake or shared user identifiers.** A constant `userid`, sequential test IDs in production, or one identifier shared by a group.

**Misrepresenting the offer.** Telling users an offer pays more than it does, or that a subscription is free when the advertiser's terms say otherwise.

**Auto-redirects and pops.** The wall opened without a deliberate user action, in a pop-under, or as a forced interstitial.

**Hidden or disguised placement.** A zero-opacity iframe, a wall behind a fake close button, or a wall presented as something other than a rewards feature.

**Proxy, VPN and datacentre traffic** presented as organic. If a meaningful share of your users are on hosting ranges, tell us why before you launch.

**Adult, hate, weapons, illegal content and infringing sites.** Whatever the traffic quality, advertisers do not accept the placement.

**Traffic bought from click farms or paid-to-click networks**, or resold from another offerwall.

## Grey areas — ask first

- **Cashback and paid-to-click products** where the reward is the primary product rather than a feature of one.
- **Kids' apps.** Age-gating and consent obligations are stricter than this platform is built for.
- **Sweepstakes and lotteries** where the reward is an entry rather than currency.
- **Reselling the wall** to third-party publishers under your own account.

None of these is automatically refused. All of them are refused if we find out afterwards.

## What happens

| Finding | Consequence |
|---|---|
| Isolated bad conversions | Rejected or reversed. Balance adjusted |
| A pattern in one app | App paused pending review |
| Deliberate fraud | Account terminated, unpaid balance forfeited, networks informed |

Reversals for prohibited traffic arrive the same way any reversal does — a negative amount on the original `trans_id`. If your handler ignores negative amounts, you will have already paid your users for traffic you are not being paid for. See [Chargebacks & reversals](/docs/chargebacks-reversals).

## Reporting it yourself

If you find a hole in your own product — a leaked test account, a bot ring, a partner sending you traffic you did not vet — tell us before the reversals do.

Publishers who report a problem get help fixing it and keep their account. Publishers who are found out do not.
