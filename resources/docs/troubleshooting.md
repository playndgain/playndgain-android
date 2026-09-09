---
title: Troubleshooting
description: Symptom, cause, fix, plus what every status code from the wall means.
---

Check this page before you open a ticket. Most of it resolves in under a minute.

## The table

| Symptom | Likely cause | Fix |
|---|---|---|
| Wall shows "No offers available" | The country has no eligible inventory, or a blacklist is too broad | Check Blacklists, then use [test mode](/docs/test-mode) with a country override |
| Wall shows "Unavailable" | The app or the account is not approved yet | Check both in the dashboard. [Going live](/docs/going-live) |
| Wall is blank in an iframe | `sandbox` without `allow-scripts allow-same-origin` | [Embedding the wall](/docs/offerwall-iframe) |
| Offer click goes nowhere | `sandbox` without `allow-popups` or `allow-top-navigation-by-user-activation` | Add both |
| European players see a consent screen and no offers | They declined consent | Correct behaviour, not a bug |
| No postback received | Callback URL blank, or your server refused it | Postback Reports shows every attempt and every response |
| Postbacks return 500 | Your endpoint threw | The response body is logged, truncated at 2KB |
| Signature always fails | Signing the wrong string, or the wrong secret | See below |
| A player was credited twice | Not deduping on `trans_id` | We retry failures. [Retries and duplicates](/docs/retries-and-failure) |
| The reward went to the wrong player | `userid` changed between the click and the conversion | Use a stable internal ID, never an email |
| A balance dropped unexpectedly | A conversion reversed | Transactions, filtered by reversed |
| Players gained coins on a chargeback | A negative amount treated as unsigned | [Reversals](/docs/chargebacks-reversals) |
| Earnings are pending, not available | Inside the hold | [Statements and payouts](/docs/statements-and-payouts) |
| `429` responses | Rate limit | [Rate limits](/docs/rate-limits) |

## What each status code means

The wall has no numeric error vocabulary to learn. It answers with HTTP status codes and with two named screens.

| Code | Cause | Fix |
|---|---|---|
| `400` | `userid` missing, too long, or containing disallowed characters | Max 191 characters, letters, digits and `_ - . @ :`. [URL parameters](/docs/url-parameters) |
| `403` | `sig` missing or wrong on an app that requires signed wall URLs | [Signed wall URLs](/docs/signed-urls) |
| `404` | `appid` does not exist | Check the App ID. It is the public one, not the secret |
| `429` | Rate limit. Sustained hits also raise a fraud flag | [Rate limits](/docs/rate-limits) |

Two conditions render a page with a `200` instead of an error, because they are answers to the player rather than faults in the request:

**"Unavailable"** means the app is not `active`, or your publisher account is not approved. It is also what an end user banned for fraud on your account sees.

**"No offers available in your country right now"** means the request was fine and nothing passed the [eligibility filter](/docs/countries-and-devices). Almost always a blacklist that is too broad, or a country with thin inventory. Turn on test mode and try a country override to find out which.

A click answers `302` to the partner's tracking URL, or redirects to a blocked screen when a fraud rule stops it. Never `301`: the tracking URL carries a single-use click ID, and a browser caching a permanent redirect would send every later click to the first click's URL and attach the conversion to the wrong row.

## Signature always fails

Four causes, in the order they actually occur.

1. **The payout was reformatted before hashing.** `0.130000` is not `0.13`. Read the raw query value, hash it, convert it afterwards.
2. **The secret belongs to a different app.** Print the app ID your handler is using next to the one in `{app_id}`. Second most common, least suspected.
3. **The wrong fields or the wrong order.** It is `trans_id + payout_usd + secret`, concatenated, no separator.
4. **Double decoding.** Some frameworks decode the query string twice. `payout_usd` contains nothing encodable, so this only bites when you rebuild the signed string from a re-encoded URL instead of from the parsed parameters.

Print both strings side by side for one failing transaction and the cause is obvious in about four seconds:

```php
error_log('signing: ' . $_GET['trans_id'] . '|' . $_GET['payout_usd']);
error_log('expected: ' . $expected . ' got: ' . $_GET['signature']);
```

## No postback received

In order:

1. **Is a callback URL configured?** Apps → Postback. Blank is valid, queues no job and raises no error.
2. **Is there a row in Postback Reports?** If yes, we sent it and the problem is your response. If no, no conversion happened, so check Conversions.
3. **Is the conversion `pending`?** Only approved conversions post back. [Conversion statuses](/docs/conversion-statuses)
4. **Does your URL resolve from outside your network?** Curl it from somewhere that is not your office.

## Postbacks succeed but players are not credited

You returned `200`, so the fault is inside your handler. The usual three:

- The `user_id` you are looking up is not the `userid` you put in the wall URL
- You are crediting `{payout_usd}`, which is dollars, instead of `{currency_amount}`
- Your dedupe key matches something it should not, so every call after the first is silently skipped. Log the branch you took

## Reports do not match

- **Test conversions are excluded** from earnings and reports. If you count them yourself your number is higher than ours
- **Reversed conversions stay visible** with a negative ledger entry. Summing conversion rows without their sign overstates earnings
- **Reports are per app.** Two apps do not roll into one line

## Still stuck

Open a ticket with the `trans_id` and the app ID. Every attempt we made, the URL, your response body and the timing are already on our side, and those two values are all we need to find them.

Send the URL or the transaction, never a screenshot of the screen. The same screen is the correct answer to six different causes.
