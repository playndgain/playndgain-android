---
title: Countries and devices
description: How geo and device are resolved, the full eligibility rules, and the two privacy endpoints.
---

Both are resolved by us, from the request. Neither is something you pass in production.

## Country

Resolved from the player's IP at wall load, as an **ISO-3166-1 alpha-2** code: `US`, `DE`, `GB`. The same value is stored on the click and returned as `{country}` on the postback.

The `country` URL parameter is honoured **only** in [test mode](/docs/test-mode). In production it is ignored, because an offer's eligibility, payout and advertiser contract are all geo-bound and a publisher-supplied country would be an invitation to farm US payouts with cheaper traffic.

## Device

Resolved from the user agent into one of four values:

| Value | Meaning |
|---|---|
| `android` | Android phone or tablet |
| `ios` | iPhone or iPad |
| `desktop` | Desktop or laptop browser |
| `other` | Anything we cannot classify |

Install offers are almost always `android` or `ios` only, so a desktop player seeing a thin wall is usually seeing that rather than a bug.

Where an offer declares a minimum OS version we compare the detected version against it and hide the offer below that.

## The full eligibility rules

An offer appears for a player only when **all** of these hold:

| Check | Rule |
|---|---|
| Status | The offer is active and its demand partner is active |
| Country | The country list is empty, or contains the resolved country |
| Device | The device list is empty, or contains the resolved device |
| OS version | No minimum, or the detected version meets it |
| Blacklists | Not blocked by offer, country, device or partner for this app |
| App country blocks | The resolved country is not in your app's blocked list |
| Payout floor | The payout is at least `$0.01` |
| Already completed | No pending or approved conversion for this player and offer, unless the offer is multi-reward |

The last rule is why an offer disappears after a player completes it and is still there for everybody else.

## Blacklists

Publisher-level, in the dashboard. Block an offer, a country, a device type or an entire demand partner. Blocks apply to every app you own.

A blacklist that is too broad is the most common cause of an empty wall. Check it before concluding there is no inventory.

## What a click stores

To resolve the above and to attribute rewards, each click stores the IP address, the resolved country, device type, OS, browser, referrer, and the `userid` you supplied.

Turn on **Anonymise IP** on the app and the address is masked before it is stored. Fraud detection degrades slightly. The trade is yours to make.

## Consent

Where the resolved country is in the EU-27, the UK or the EEA, the wall shows a consent screen before any offer, unless consent is already stored for that app and player.

The screen states what is collected, why, and who receives it, which is the demand partner behind whichever offer the player chooses. Accept and decline carry equal weight. Declining shows an explanation and no offers, and no click is possible.

Two data controls are available to you, both signed with your app secret:

```txt
GET  /privacy/data?appid=&userid=&sig=      what we hold on that player, as JSON
POST /privacy/delete                        anonymise their clicks and conversions
```

Deletion nulls the IP and replaces the identifier with a hash. **The money rows are retained**, because financial records are not deletable and that retention basis is stated in the privacy policy. See [The ledger](/docs/the-ledger).

**We are not a registered IAB TCF vendor.** If you send us a TC string we log it and pass it to any partner that accepts one, but nothing here claims TCF compliance. Claiming it without registering would be worse than not having it.
