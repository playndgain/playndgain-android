---
title: URL parameters
description: Every parameter the wall accepts, typed, with the rules for userid.
---

Query parameters on `{APP_URL}/wall`.

| Parameter | Type | Required | Notes |
|---|---|---|---|
| `appid` | int | yes | The public App ID from the dashboard. Not the secret |
| `userid` | string | yes | Your own opaque identifier. Echoed back on every postback. Max 191 characters |
| `sig` | string | conditional | Required when the app has **Require signed wall URLs** on. `sha256(appid + userid + secret_key)` |
| `country` | string | no | ISO-3166-1 alpha-2, to preview another geo. **Ignored unless the app is in test mode** |
| `theme` | string | no | `light` or `dark`. Defaults to the app's configured theme |
| `lang` | string | no | ISO-639-1. `en` and `ar` ship today. Anything else falls back to `en` |
| `sub1` … `sub5` | string | no | Your own tracking values. Letters, digits and `_ - . @ : \|`, up to 100 characters each. Stored on the click and echoed back as `{sub1}` … `{sub5}` on the postback. A value outside those rules is dropped, not refused |

The native SDKs also add `sdk`, `sdk_v`, `model`, `dh` and `ds`. They describe the device, are used for traffic quality only, and you never set them yourself.

## userid

Letters, digits, and `_ - . @ :`. Anything else is a `400`.

Identifiers that work:

```txt
4812                                  your internal primary key
u_4812                                the same, prefixed
9f2b1c8a4e7d3f60                      an opaque hash
```

Identifiers that will cost you money:

```txt
alice@example.com                     changes the day she changes her email
Alice Smith                           has whitespace, and is not unique
${user.id}                            a template you forgot to render, echoed back forever
```

The rule behind all three: the string must still identify the same person weeks after the click, because that is when the conversion can arrive.

## country

Honoured in test mode only. In production the country is resolved from the player's IP.

An offer's eligibility, payout and advertiser contract are all geo-bound. A publisher-supplied country in production would be an open invitation to farm US payouts with cheaper traffic, so the parameter is ignored rather than trusted.

## Examples

Minimum:

```txt
{APP_URL}/wall?appid=YOUR_APP_ID&userid=4812
```

Signed, dark, Arabic:

```txt
{APP_URL}/wall?appid=YOUR_APP_ID&userid=4812&theme=dark&lang=ar&sig=b91f…
```

Test mode, previewing Germany:

```txt
{APP_URL}/wall?appid=YOUR_APP_ID&userid=qa-1&country=DE
```

## Encode your values

`userid` goes into a query string. If yours can contain `@` or `:`, encode it:

```php
$url = '{APP_URL}/wall?appid=YOUR_APP_ID&userid=' . rawurlencode($userId);
```

We decode once and echo back exactly what you sent. If you also sign the URL, sign the **decoded** value: we verify against what we decoded, not against what we received.
