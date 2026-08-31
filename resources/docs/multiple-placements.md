---
title: Multiple placements
description: One app or many — and how to tell which one you need.
---

An app is one integration: one App ID, one secret, one currency rate, one callback URL, one set of reports.

## Use one app when

The wall appears in several places in the **same** product, with the same user accounts and the same currency.

A rewards tab, a "get more coins" modal and a post-level interstitial in one game are one app. Same users, same coins, same callback. Splitting them buys you nothing and costs you a second secret to rotate.

## Use separate apps when

Any of these differ:

| Difference | Why it needs its own app |
|---|---|
| **User namespace** | Two products with independent account systems will collide on `userid`. |
| **Currency** | Coins and Gems need different rates. The rate lives on the app. |
| **Callback endpoint** | One app has exactly one callback URL. |
| **Platform** | iOS and Android builds have different eligible offers and are worth reporting on separately. |
| **Country blocks** | Blocks are per app. |
| **Theme** | Themes are per app. |

Reporting is per app, so this is also the decision about how your revenue reports break down. That is usually the deciding factor: if you want to see two lines in the dashboard, make two apps.

## Telling placements apart within one app

You cannot pass a custom parameter through the wall and get it back on the postback. There is no `sub_id` in v1.

If you need per-placement attribution inside one app, encode it in the `userid` and split it on the way back:

```php
// rendering the iframe
$userId = $user->id . ':lobby';

// in your callback
[$id, $placement] = explode(':', $_GET['user_id'], 2) + [1 => 'unknown'];
```

The cost is real and you should weigh it: `userid` must stay stable for the lifetime of a reward. If a user starts an offer from the lobby and finishes it days later, the conversion carries `4812:lobby` regardless of where they were when it completed. You are attributing to the click, not to the session.

For anything more than a coarse split, use separate apps.

## Rate limits are per app

The wall allows 60 requests per minute per IP and **600 per minute per app**. A single app serving several high-traffic placements shares that budget. See [Rate limits](/docs/rate-limits).

## What is shared across your apps

- Your publisher account, revenue share and balance.
- Your withdrawal settings and payment methods.
- Publisher-level blacklists — blocking a network or a country once applies to every app you own.

Secrets, currency rates, callback URLs, themes and country blocks are per app and never shared.
