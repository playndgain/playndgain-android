---
title: Rate limits
description: The limits, what exceeding them costs beyond a 429, and the four habits that stay under them.
---

| Route | Limit |
|---|---|
| Wall, `{APP_URL}/wall` | 60 per minute per IP, **and** 600 per minute per app |
| Offer click, `/click/*` | 20 per minute per IP and player |
| Privacy endpoints, `/privacy/*` | 10 per minute per IP |
| Postback re-send, from the dashboard | 10 per minute per app |

Over a limit you get a `429` with a plain retry message. There is no `Retry-After` header, and a minute is always enough because every limit above is per minute.

## Both wall limits apply at once

The per-IP limit is about one abusive player. The per-app limit is about your whole integration, and one app serving several placements shares it. See [One app or several](/docs/multiple-placements).

600 a minute is ten wall loads a second, sustained. If you are legitimately above that, talk to us before you launch rather than after.

## A 429 is also a fraud signal

Repeatedly hitting a limit writes a fraud event at flag level against the app. It is read as a signal about the traffic, not only as load: a genuine wall does not get loaded sixty times a minute from one address.

A burst does nothing. Sustained hits get reviewed.

## Four habits that keep you under

**Do not reload the iframe on a timer.** The wall is a page, not a polling endpoint. If you want fresh offers after a conversion, reload once when the player comes back to the tab.

**Do not preload the wall on every page.** Load it when the player opens the rewards screen.

**Honour the cache headers.** The wall sends `Cache-Control: private, no-store` because the offer list is per player and completed offers disappear from it. Do not layer your own cache on top, and do not re-request to work around one.

**Do not proxy the wall through your own server.** Every player then shares your server's IP, and you hit the 60-per-minute per-IP limit at a handful of concurrent users. Embed the iframe directly.

## Outbound postbacks are not limited

We do not throttle deliveries to your endpoint. If a large batch clears at once, you receive them as fast as our workers can send.

If that is a problem for your endpoint, answer `200` immediately and queue the work on your side. Do not answer `429` at us: it is not a 2xx, so we treat it as a failure and [retry it](/docs/retries-and-failure) five more times, which is the opposite of what you wanted.
