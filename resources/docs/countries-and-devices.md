---
title: Countries & devices
description: How geo and device are resolved, and what that means for eligibility.
---

Both are resolved by us, from the request. Neither is something you pass in production.

## Country

Resolved from the end user's IP at wall load, as an **ISO-3166-1 alpha-2** code — `US`, `DE`, `GB`. The same value is stored on the click and returned as `{country}` on the postback.

The `country` URL parameter is honoured **only** in [test mode](/docs/test-mode). In production it is ignored.

An offer reaches the wall when its country list is empty (worldwide) **or** contains the resolved country, and the country is not blocked for your app.

## Device

Resolved from the user agent, into one of four values:

| Value | Meaning |
|---|---|
| `android` | Android phone or tablet |
| `ios` | iPhone or iPad |
| `desktop` | Desktop or laptop browser |
| `other` | Anything we cannot classify |

An offer reaches the wall when its device list is empty **or** contains the resolved device. CPI offers are almost always `android` or `ios` only — a desktop user seeing few offers is usually seeing this, not a bug.

Where an offer declares a minimum OS version, we compare the detected version against it and hide the offer when the device is below.

## Full eligibility rules

An offer appears for a user only when **all** of these hold:

| Check | Rule |
|---|---|
| Status | The offer is active and its network is active |
| Country | Country list is empty, or contains the resolved country |
| Device | Device list is empty, or contains the resolved device |
| OS version | No minimum, or the detected version meets it |
| Blacklists | Not blocked by offer, country, device or network for this app |
| App country blocks | The resolved country is not in your app's blocked list |
| Payout floor | Payout is at least `$0.01` |
| Already completed | No pending or approved conversion for this user and offer, unless the offer is multi-reward |

The last one is why an offer disappears after a user completes it, and why it is still there for a different user.

## Blacklists

Publisher-level, in the dashboard. You can block an offer, a country, a device type or an entire network. Blocks apply to every app you own.

A blacklist that is too broad is the most common cause of an empty wall. Check it before assuming there is no inventory.

## What we collect

To resolve the above and to attribute rewards, each click stores: IP address, resolved country, device type, OS, browser, referrer and the `userid` you supplied.

If you enable **Anonymise IP** on the app, the address is masked before it is stored. Fraud detection degrades slightly; the trade is yours to make.

## Consent

Where the resolved country is in the EU-27, the UK or the EEA, the wall shows a consent screen before any offer, unless consent is already stored for that `(app, end user)` pair.

The screen states what is collected, why, and who receives it — the partner network behind whichever offer the user chooses. Accept and decline carry equal weight. Declining shows an explanation and **no offers**; no click is possible.

Two data controls are available to you, both signed with your app secret:

```txt
GET  /privacy/data?appid=&userid=&sig=      what we hold on that end user, as JSON
POST /privacy/delete                        anonymise clicks and conversions for that end user
```

Deletion nulls the IP and replaces the end user identifier with a hash. **The money rows are retained** — financial records are not deletable, and that retention basis is stated in the privacy policy.

**We are not a registered IAB TCF vendor.** If you send us a TC string we log it and pass it to any network that accepts one, but nothing on this site claims TCF compliance. Claiming it without registration would be worse than not having it.
