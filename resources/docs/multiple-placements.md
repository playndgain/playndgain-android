---
title: One app or several
description: When to split an integration, and how to attribute placements inside one app.
---

An app is one integration: one App ID, one secret, one currency rate, one callback URL, one set of reports.

## Use one app

When the wall appears in several places in the **same** product, with the same accounts and the same currency.

A rewards tab, a "get more coins" modal and a post-level interstitial in one game are one app. Same players, same coins, same callback. Splitting them buys nothing and costs you a second secret to rotate.

## Use separate apps

When any of these differ:

| Difference | Why it needs its own app |
|---|---|
| **Player namespace** | Two products with independent accounts will collide on `userid` |
| **Currency** | Coins and Gems need different rates, and the rate lives on the app |
| **Callback endpoint** | An app has exactly one callback URL |
| **Platform** | iOS and Android builds get different eligible offers and are worth reporting separately |
| **Country blocks** | Blocks are per app |
| **Theme** | Themes are per app |

Reporting is per app, so this is also the decision about how your revenue breaks down on the dashboard. That is usually what settles it: if you want to see two lines, make two apps.

## Telling placements apart inside one app

There is no `sub_id` and no pass-through custom parameter. Nothing you add to the wall URL comes back on the postback except `userid`.

So if you need a coarse split inside one app, put it in the `userid` and take it out again on the way back:

```php
// rendering the iframe
$userId = $player->id . ':lobby';

// in your callback
[$id, $placement] = explode(':', $_GET['user_id'], 2) + [1 => 'unknown'];
```

Weigh the cost before you do this. `userid` has to stay stable for the lifetime of a reward, so a player who starts an offer in the lobby and finishes it three days later still converts as `4812:lobby` no matter where they were at the time. You are attributing to the click, not to the session, and no amount of care changes that.

For anything finer than a coarse split, use separate apps.

## What is shared and what is not

Shared across every app you own:

- Your publisher account and your balance
- Your payment methods and payout settings
- Publisher-level blacklists. Blocking a country or a demand partner once applies everywhere

Never shared: secrets, currency rates, callback URLs, themes and country blocks.

## Rate limits are per app

The wall allows 60 requests per minute per IP **and** 600 per minute per app. One app serving several busy placements shares that budget. See [Rate limits](/docs/rate-limits).
