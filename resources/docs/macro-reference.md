---
title: Macro reference
description: Every macro you can put in your callback URL, typed, with an example.
---

Put these in your callback URL in `{braces}`. We substitute them at send time and URL-encode every value.

This table is the public contract. Renaming or dropping a macro breaks every integration built against it, so it does not happen.

## The macro set

| Macro | Type | Example | Notes |
|---|---|---|---|
| `{user_id}` | string | `USER_123` | Exactly what was passed as `userid` on the wall |
| `{trans_id}` | string | `OFFER19624243-Gw2CpY` | Unique per conversion. **Use this for your own dedupe.** |
| `{offer_id}` | int | `19624243` | Empty for a conversion from an embedded partner offerwall — there is no offer of ours to name. Do not key on it. |
| `{offer_name}` | string | `Badoo%3A%20Dating` | URL-encoded |
| `{payout_usd}` | decimal | `0.130000` | Publisher's share, USD, 6 dp |
| `{currency_amount}` | decimal | `130.00` | `payout_usd × currency_rate` |
| `{app_id}` | int | `10042` | |
| `{country}` | string | `US` | ISO-2 |
| `{ip}` | string | `167.224.159.233` | End user's IP at click time |
| `{status}` | string | `credited` \| `reversed` | |
| `{timestamp}` | int | `1754179200` | Unix seconds |
| `{signature}` | string | `a3f9…` | See below |

## Signature

```txt
signature = sha256( trans_id + payout_usd + app_secret )
```

`payout_usd` is the string **exactly as it appears in the URL** — six decimal places, no thousands separator. On a reversal it carries the minus sign and the signature covers it, so a reversal cannot be replayed as a credit.

Runnable verification in five languages: [Signature verification](/docs/signature-verification).

## Negative on a reversal

`{payout_usd}` and `{currency_amount}` are **negative** when `{status}` is `reversed`. `{trans_id}` is the **original** transaction's ID, not a new one, so you can find the credit to undo.

If your handler treats amounts as unsigned you will credit a user for a chargeback. [Chargebacks & reversals](/docs/chargebacks-reversals) is the page that matters most on this site.

## Everything is URL-encoded

Values are `rawurlencode`d before substitution. `Badoo: Dating` arrives as `Badoo%3A%20Dating`. Your framework decodes query parameters for you; if you are parsing the query string by hand, decode before you use it — and **do not decode `payout_usd` before hashing it**, because the signature covers the string as sent.

## Build your URL

<div class="doc-builder" x-data="docsUrlBuilder"><p class="doc-builder-label">Your endpoint</p><input type="url" class="doc-builder-input" x-ref="base" x-model="base" value="https://yoursite.com/advault/callback" spellcheck="false"><p class="doc-builder-label">Macros</p><div class="doc-builder-grid"><label><input type="checkbox" x-model="picked" value="user_id" checked> <code>user_id</code></label><label><input type="checkbox" x-model="picked" value="trans_id" checked> <code>trans_id</code></label><label><input type="checkbox" x-model="picked" value="offer_id"> <code>offer_id</code></label><label><input type="checkbox" x-model="picked" value="offer_name"> <code>offer_name</code></label><label><input type="checkbox" x-model="picked" value="payout_usd" checked> <code>payout_usd</code></label><label><input type="checkbox" x-model="picked" value="currency_amount" checked> <code>currency_amount</code></label><label><input type="checkbox" x-model="picked" value="app_id"> <code>app_id</code></label><label><input type="checkbox" x-model="picked" value="country"> <code>country</code></label><label><input type="checkbox" x-model="picked" value="ip"> <code>ip</code></label><label><input type="checkbox" x-model="picked" value="status" checked> <code>status</code></label><label><input type="checkbox" x-model="picked" value="timestamp"> <code>timestamp</code></label><label><input type="checkbox" x-model="picked" value="signature" checked> <code>signature</code></label></div><output class="doc-builder-out" x-text="url">https://yoursite.com/advault/callback?user_id={user_id}&amp;trans_id={trans_id}&amp;payout_usd={payout_usd}&amp;currency_amount={currency_amount}&amp;status={status}&amp;signature={signature}</output><button type="button" class="doc-builder-copy" x-cloak x-text="label" @click="copy">Copy</button></div>

Paste the result into **Apps → Postback**. Parameter names are yours to choose — the macro is what matters, the key beside it is whatever your endpoint reads.

## Macros we do not have

There is no `sub_id` and no pass-through custom parameter in v1. If you need per-placement attribution, [Multiple placements](/docs/multiple-placements) covers the two ways to get it.
