---
title: Macro reference
description: Every macro you can put in your callback URL, typed, with a builder that assembles it.
---

Put these in your callback URL in `{braces}`. We substitute them at send time and URL-encode every value.

This table is the public contract. Renaming or dropping a macro would break every integration built against it, so neither happens.

## The set

| Macro | Type | Example | Notes |
|---|---|---|---|
| `{user_id}` | string | `USER_123` | Exactly what you passed as `userid` on the wall |
| `{trans_id}` | string | `OFFER19624243-Gw2CpY` | Unique per conversion. **This is your dedupe key** |
| `{offer_id}` | int | `19624243` | Empty for a conversion from an embedded partner wall, where there is no offer of ours to name. Do not key on it |
| `{offer_name}` | string | `Badoo%3A%20Dating` | URL-encoded |
| `{payout_usd}` | decimal | `0.130000` | Your payout, USD, six decimal places |
| `{currency_amount}` | decimal | `130.00` | `payout_usd × your rate`. This is what the player gets |
| `{app_id}` | int | `10042` | |
| `{country}` | string | `US` | ISO-2 |
| `{ip}` | string | `167.224.159.233` | The player's IP at click time |
| `{status}` | string | `credited` \| `reversed` | Only these two values ever reach you |
| `{timestamp}` | int | `1754179200` | Unix seconds. Regenerated on every attempt, so never dedupe on it |
| `{signature}` | string | `a3f9…` | See below |
| `{sub1}` | string | `spring_promo` | The `sub1` you passed on the wall URL or in the SDK options. Empty when not sent, and always empty for partner wall conversions |
| `{sub2}` | string | | Same, for `sub2` |
| `{sub3}` | string | | Same, for `sub3` |
| `{sub4}` | string | | Same, for `sub4` |
| `{sub5}` | string | | Same, for `sub5` |

## The signature

```txt
signature = sha256( trans_id + payout_usd + app_secret )
```

`payout_usd` is the string **exactly as it appears in the URL**: six decimal places, no thousands separator, and a leading minus on a reversal. The signature covers that minus sign, which is what stops a reversal being replayed at you as a credit.

Runnable verification in five languages is on [Verifying the signature](/docs/signature-verification).

## Two macros go negative

`{payout_usd}` and `{currency_amount}` are **negative** whenever `{status}` is `reversed`. `{trans_id}` is then the **original** transaction's ID rather than a new one, so you can find the credit to undo.

A handler that treats amounts as unsigned credits a player for a chargeback. [Reversals](/docs/chargebacks-reversals) is the page that matters most on this site.

## Everything is URL-encoded

Values are `rawurlencode`d before substitution, so `Badoo: Dating` arrives as `Badoo%3A%20Dating`. Your framework decodes query parameters for you.

If you parse the query string by hand, decode before you use a value, and **do not decode `payout_usd` before hashing it**. The signature covers the string as sent.

## Build your URL

<div class="doc-builder" x-data="docsUrlBuilder"><p class="doc-builder-label">Your endpoint</p><input type="url" class="doc-builder-input" x-ref="base" x-model="base" value="https://yoursite.com/playndgain/callback" spellcheck="false"><p class="doc-builder-label">Macros</p><div class="doc-builder-grid"><label><input type="checkbox" x-model="picked" value="user_id" checked> <code>user_id</code></label><label><input type="checkbox" x-model="picked" value="trans_id" checked> <code>trans_id</code></label><label><input type="checkbox" x-model="picked" value="offer_id"> <code>offer_id</code></label><label><input type="checkbox" x-model="picked" value="offer_name"> <code>offer_name</code></label><label><input type="checkbox" x-model="picked" value="payout_usd" checked> <code>payout_usd</code></label><label><input type="checkbox" x-model="picked" value="currency_amount" checked> <code>currency_amount</code></label><label><input type="checkbox" x-model="picked" value="app_id"> <code>app_id</code></label><label><input type="checkbox" x-model="picked" value="country"> <code>country</code></label><label><input type="checkbox" x-model="picked" value="ip"> <code>ip</code></label><label><input type="checkbox" x-model="picked" value="status" checked> <code>status</code></label><label><input type="checkbox" x-model="picked" value="timestamp"> <code>timestamp</code></label><label><input type="checkbox" x-model="picked" value="signature" checked> <code>signature</code></label></div><output class="doc-builder-out" x-text="url">https://yoursite.com/playndgain/callback?user_id={user_id}&amp;trans_id={trans_id}&amp;payout_usd={payout_usd}&amp;currency_amount={currency_amount}&amp;status={status}&amp;signature={signature}</output><button type="button" class="doc-builder-copy" x-cloak x-text="label" @click="copy">Copy</button></div>

Paste the result into **Apps → Postback**. The parameter names are yours to choose. The macro is the contract, and the key beside it is whatever your endpoint happens to read.

## Macros we do not have

There is no `sub_id` and no pass-through custom parameter. If you need per-placement attribution, [One app or several](/docs/multiple-placements) covers the two ways to get it and the cost of each.
