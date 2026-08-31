---
title: Rate limits
description: The limits, what exceeding them costs, and how to stay under them.
---

| Route | Limit |
|---|---|
| Wall — `{APP_URL}/wall` | 60 per minute per IP, **and** 600 per minute per app |
| Offer click — `/click/*` | 20 per minute per IP + end user |
| Privacy endpoints — `/privacy/*` | 10 per minute per IP |
| Postback re-send, from the dashboard | 10 per minute per app |

Exceeding a limit returns `429` with a plain retry message.

## Both wall limits apply

The per-IP limit is about one abusive user. The per-app limit is about your whole integration. A single app serving several placements shares the 600 — see [Multiple placements](/docs/multiple-placements).

600 per minute is ten wall loads a second, sustained. If you are legitimately above that, talk to us before you launch rather than after.

## Sustained 429s are a fraud signal

Hitting a rate limit repeatedly writes a fraud event at flag level against the app. It is treated as a signal about the traffic, not only as a load problem — a legitimate wall does not get loaded sixty times a minute from one address.

Nothing happens on a burst. Sustained hits are what get reviewed.

## Staying under them

**Do not reload the iframe on a timer.** The wall is a page, not a polling endpoint. If you want fresh offers after a conversion, reload once when the user comes back to the tab.

**Do not preload the wall on every page of your site.** Load it when the user opens the rewards screen.

**Honour the cache headers.** The wall sends `Cache-Control: private, no-store` because the offer list is per user and completed offers disappear from it. Do not layer your own cache on top and do not re-request it to work around one.

**Do not proxy the wall through your own server.** Every user then shares your server's IP and you will hit the 60-per-minute per-IP limit at a handful of concurrent users. Embed the iframe directly.

## Outbound postbacks are not rate limited

We do not throttle deliveries to your endpoint. If a large batch of conversions clears at once you will receive them as fast as our workers can send them.

If that is a problem for your endpoint, return `200` immediately and queue the work on your side. Do not return `429` at us — it is not a 2xx, so we treat it as a failure and [retry it](/docs/retries-and-failure) five more times, which is the opposite of what you wanted.

## When you get a 429

Back off and retry. There is no `Retry-After` header in v1; a minute is always enough, since every limit above is per minute.
