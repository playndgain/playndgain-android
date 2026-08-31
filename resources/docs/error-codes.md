---
title: Error responses
description: What each status code from the wall means, and what the wall's own screens mean.
---

There is no numeric error-code vocabulary to learn. The wall answers with HTTP status codes and with two named screens.

## HTTP responses from the wall

| Code | Cause | Fix |
|---|---|---|
| `400` | `userid` missing, too long, or containing disallowed characters | Max 191 characters, letters, digits and `_ - . @ :`. See [URL parameters](/docs/url-parameters) |
| `403` | `sig` missing or wrong on an app that requires signed wall URLs | [Signed URLs](/docs/signed-urls) |
| `404` | `appid` does not exist | Check the App ID. It is the public one, not the secret |
| `429` | Rate limit | [Rate limits](/docs/rate-limits). Sustained hits also raise a fraud flag |

## Screens, not status codes

Two conditions render a page with a `200` rather than an error, because they are answers to the user rather than faults in the request.

**"Unavailable"** — the app is not `active`, or your publisher account is not approved. Check both in the dashboard. Also shown to an end user who has been banned for fraud on your account.

**"No offers available in your country right now"** — the request was fine and nothing passed the [eligibility filter](/docs/countries-and-devices). Almost always a blacklist that is too broad, or a country with thin inventory. Turn on [test mode](/docs/test-mode) and try a country override to confirm which.

## The consent screen

Not an error. In the EU-27, the UK and the EEA, a user with no stored consent gets the consent screen. Declining shows an explanation and no offers, and no click is possible.

## Click responses

`/click/{offer}` answers with a `302` to the network's tracking URL, or redirects to a blocked screen when a fraud rule stops the click.

A blocked click still writes a row on our side, with the reason. It is visible in your Clicks report — a blocked click that produced no record would be a support question nobody could answer.

Never a `301`: the tracking URL contains a single-use click ID, and a browser that cached a permanent redirect would send every later click to the first click's URL and attach the conversion to the wrong row.

## Postback responses

These run the other way — they are what **your** endpoint returns to **us**. Everything outside `2xx` is a failure and is retried. See [Retries & failure](/docs/retries-and-failure).

## Reporting one

Every response you can get from us is reproducible from a URL. When you open a ticket, send the full wall URL or the `trans_id`, not a screenshot of the screen — the screen is the same for six different causes.
