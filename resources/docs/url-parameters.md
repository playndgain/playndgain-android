---
title: URL parameters
description: Every parameter the offerwall accepts, typed.
---

Query parameters on `{APP_URL}/wall`.

| Parameter | Type | Required | Notes |
|---|---|---|---|
| `appid` | int | yes | Public App ID from the dashboard. Not the secret. |
| `userid` | string | yes | Your own opaque user identifier. Echoed back in every postback. Max 191 characters. |
| `sig` | string | conditional | Required when the app has **Require signed wall URLs** enabled. `sha256(appid + userid + secret_key)`. |
| `country` | string | no | ISO-3166-1 alpha-2 override for previewing another geo. **Ignored unless the app is in test mode.** |
| `theme` | string | no | `light` or `dark`. Defaults to the app's configured theme. |
| `lang` | string | no | ISO-639-1. `en` and `ar` ship today; anything else falls back to `en`. |

## userid

Allowed characters are letters, digits, and `_ - . @ :`. Anything else is rejected with a `400`.

Reasonable identifiers:

```txt
4812                                  internal primary key
u_4812                                prefixed key
9f2b1c8a4e7d3f60                      opaque hash
```

Unreasonable ones:

```txt
alice@example.com                     changes when they change their email
Alice Smith                           whitespace, and it is not unique
${user.id}                            an untemplated string, and we will echo it back forever
```

## country

Only honoured in test mode. In production the country is resolved from the end user's IP, because an offer's eligibility, payout and advertiser contract are all geo-bound. A publisher-supplied country in production would be an open invitation to farm US payouts from cheaper traffic.

## Examples

Minimum:

```txt
{APP_URL}/wall?appid=YOUR_APP_ID&userid=4812
```

Signed, dark theme, Arabic:

```txt
{APP_URL}/wall?appid=YOUR_APP_ID&userid=4812&theme=dark&lang=ar&sig=b91f…
```

Test mode, previewing Germany:

```txt
{APP_URL}/wall?appid=YOUR_APP_ID&userid=qa-1&country=DE
```

## URL-encode your values

`userid` goes into a query string. If yours can contain `@` or `:`, encode it:

```php
$url = '{APP_URL}/wall?appid=YOUR_APP_ID&userid=' . rawurlencode($userId);
```

We decode it once and echo back exactly what you sent.
