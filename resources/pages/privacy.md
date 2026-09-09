---
title: Privacy policy
description: What PLAYNDGAIN collects, why, who receives it, and how an end user gets it back or erased.
note: Drafted from the product specification (docs/00 §8, docs/03 §4) to describe what the system actually does. It has not been reviewed by counsel. Before launch a lawyer must review it and add the operating entity, its address, its data-protection contact, and the transfer mechanism used for non-EU networks.
---

Last updated: 2026-09-01

PLAYNDGAIN is a rewarded offerwall. Publishers embed our wall in their own app or game, their players complete offers supplied by partner advertising networks, and we pay the publisher for each verified conversion.

That shape decides this policy. There are two different groups of people in it and they are not treated the same:

- **End users** — the people who see the offerwall inside a publisher's product. We never know who they are. This is most of the policy.
- **Publishers** — the people who hold an account with us. We know their email, because they signed up.

## What we hold about an end user

The offerwall is loaded with an app ID and a `userid` that the publisher supplies. That `userid` is **an opaque string chosen by the publisher** — usually a database row ID from their own system. It means nothing to us and we make no attempt to resolve it to a person.

Around it we store, per click and per conversion:

| Field | Where it comes from | Why it exists |
|---|---|---|
| `userid` | The publisher, in the iframe URL | To tell the publisher which of their users to credit |
| IP address | The request | Geo-targeting, and the fraud rules that catch datacentre and proxy traffic |
| Country | Derived from the IP | Offers are targeted by country |
| Device type, OS, browser | The `User-Agent` header | Offers are targeted by device, and a mismatch is a fraud signal |
| Click and conversion records | Our own system | The money path. Which offer, when, what it paid |

**IP anonymisation is a per-app switch.** When a publisher turns it on for an app, the address is masked before it is written — the last octet of an IPv4 address, the last 80 bits of an IPv6 address — for every click, conversion, and consent record from that app. The unmasked address is never stored for that app.

## What we deliberately do not collect

- **No email addresses or names of end users.** There is nowhere in the system to put one.
- **No device advertising identifiers.** No IDFA, no GAID, no fingerprinting SDK.
- **No third-party analytics or advertising tags on the offerwall.** The wall loads from our origin and nothing else.
- **No cross-publisher profile.** Records are scoped to one app. The same person inside two publishers' products is two unrelated sets of rows to us, because their `userid` values come from two different systems.

## Consent in the EU

When the offerwall is loaded by someone in a country where consent is required, it does not render offers. It renders a consent screen first, stating what is collected, why, and who receives it, with **accept and decline weighted equally**. There is no pre-ticked box and no "reject" hidden behind a second screen.

The three purposes on that screen are the only three:

- **Fraud prevention** — the rules that decide whether a click or a conversion is genuine.
- **Reward attribution** — matching a completed offer back to the `userid` that has to be credited.
- **Network delivery** — passing the click to the partner network that owns the offer.

**Decline is honoured.** No offers are rendered, no click can be made, and no further processing happens. Consent is recorded against the app and the `userid`, not against a session, so it survives the iframe reloading and is not asked again on every page view.

The consent record itself holds the decision, the three purposes, the country, the IP under the app's own anonymisation setting, and a **hash** of the user agent — a hash rather than the string, so the record proves which client agreed without keeping a fingerprintable value.

**We are not a registered IAB TCF vendor.** We do not read or write a TCF consent string, and a TCF signal from a publisher's CMP is not something we can act on. Our consent gate is our own.

## Who the data goes to

**Partner advertising networks.** Completing an offer means leaving for the network that owns it. The redirect carries our click identifier and, as any HTTP request does, the user's IP address and user agent — which the network then processes under its own policy, as an independent controller. We do not send them the publisher's `userid`.

**The publisher.** When a conversion is confirmed we fire a signed callback to the publisher's own server carrying their `userid`, the transaction ID, the amount, the country and the IP. It is their user; they are being told to credit them.

**Nobody else.** We do not sell data, we do not share it for advertising unrelated to the offer the user chose, and we run no data broker relationships.

## Getting the data, and getting it erased

Two endpoints exist for this and they are part of the product, not a support process:

- **`GET /privacy/data`** returns everything held against one `userid` in one app — the consent record, the clicks, the conversions.
- **`POST /privacy/delete`** anonymises it. The IP is redacted, the `userid` is replaced with a hash that cannot be reversed to the original, the user agent and referrer are dropped, and the consent record is deleted outright.

Both require a valid signature computed with the app's secret key, whatever the app's other settings say. An unsigned data endpoint would hand everything held on a user to anyone who could guess an app ID and a user ID, which is the disclosure the endpoint exists to control. In practice the publisher calls them on their user's behalf, because the publisher is the one who holds the secret key and knows which `userid` belongs to whom.

**What deletion does not erase: the money.** Conversions and ledger entries survive it, with the identifiers stripped. We are required to keep accurate financial records, an advertiser can reverse a conversion months after it happened, and a ledger that can be edited is a ledger that settles no disputes. This is a legitimate-interest retention basis and it is stated here rather than assumed.

## Retention

Click and conversion rows are kept for as long as the money they represent can still move — reversals arrive up to and beyond 30 days after a conversion, and financial records outlive that. Consent records are kept while the consent stands and are deleted on erasure. Raw request logs are short-lived operational data.

## Cookies and local storage

**The offerwall sets no cookies at all.** It is stateless by design: no session, no cookie, no CSRF token. It runs inside an iframe on someone else's domain and authenticates with the app ID, the user ID and a signature instead. Nothing about the wall needs a cookie, so it does not have one.

**This marketing site** sets one first-party session cookie, and only when you submit a form — the contact form or the newsletter box in the footer — because the form carries a CSRF token. **The documentation** keeps the position of its sidebar in `sessionStorage` for the length of the tab, and nothing else. Neither is used for tracking and neither leaves this origin.

There are no third-party scripts on this site. Fonts are self-hosted rather than fetched from a font CDN, which is a deliberate choice on a site that talks about privacy.

## Publishers

If you hold an account, we hold what you gave us: your email address, your account and company details, your payment method details, and the record of every app, conversion, withdrawal and admin action on your account. Admin actions that affect money or bans are written to an audit log with the actor, the target, and the before and after values — including actions taken while an administrator is impersonating your account, which is visible to you in the panel while it is happening.

Your account data is kept while the account exists and for as long afterwards as the financial records require.

## Changes

Material changes are announced in the publisher dashboard before they take effect. The date at the top of this page is the date of the last change.

## Contact

Data questions, access requests you cannot resolve through your publisher, and anything else on this page: [get in touch](/contact).
