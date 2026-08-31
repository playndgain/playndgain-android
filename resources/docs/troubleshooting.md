---
title: Troubleshooting
description: Symptom, cause, fix. The table to check before opening a ticket.
---

| Symptom | Likely cause | Fix |
|---|---|---|
| Wall shows "No offers available" | Country has no eligible inventory, or a blacklist is too broad | Check Blacklists; use [test mode](/docs/test-mode) with a country override |
| Wall shows "Unavailable" | App or account not approved yet | Check status in the dashboard |
| Wall is blank in an iframe | `sandbox` without `allow-scripts allow-same-origin` | See [Offerwall iframe](/docs/offerwall-iframe) |
| Offer click goes nowhere | `sandbox` without `allow-popups` or `allow-top-navigation-by-user-activation` | Add both |
| European users see a consent screen and no offers | They declined consent | Correct behaviour, not a bug |
| No postback received | Callback URL blank, or your server rejected it | Postback Reports shows every attempt and the response |
| Postbacks return 500 | Your endpoint threw | Response body is logged, truncated to 2 KB |
| Signature always fails | Signing the wrong string, or the wrong secret | Concatenate `trans_id + payout_usd` exactly as received, no rounding |
| User credited twice | Not deduping on `trans_id` | We retry failures. [Dedupe](/docs/idempotency) |
| Reward went to the wrong user | `userid` changed between click and conversion | Use a stable internal ID, never an email |
| Balance dropped unexpectedly | A conversion reversed | Transactions → filter by reversed |
| Users gained coins on a chargeback | Negative amount treated as unsigned | [Chargebacks & reversals](/docs/chargebacks-reversals) |
| Earnings pending, not available | Inside the calendar-month hold | Dashboard shows the clearing date per conversion |
| 429 responses | Rate limit | [Rate limits](/docs/rate-limits) |

## Signature always fails

Four causes, in the order they actually occur.

1. **The payout was reformatted before hashing.** `0.130000` is not `0.13`. Read the raw query value, hash it, and convert to a number afterwards.
2. **The secret belongs to a different app.** Print the app ID your handler is using next to the one in `{app_id}`. This is the second most common cause and the least suspected.
3. **The wrong fields, or the wrong order.** It is `trans_id + payout_usd + secret`, concatenated with no separator.
4. **Double decoding.** Some frameworks decode the query string twice. `payout_usd` contains no encodable characters, so this only bites when you build the signed string from a re-encoded URL rather than from the parsed parameters.

Print both strings — yours and ours — side by side for one failing transaction and the cause is obvious in about four seconds:

```php
error_log('signing: ' . $_GET['trans_id'] . '|' . $_GET['payout_usd']);
error_log('expected: ' . $expected . ' got: ' . $_GET['signature']);
```

## No postback received

In order:

1. **Is a callback URL configured?** Apps → Postback. Blank is a valid configuration — it queues no job and raises no error.
2. **Is there a row in Postback Reports?** If yes, we sent it and the problem is the response. If no, no conversion happened — check Conversions.
3. **Is the conversion `pending`?** Only approved conversions post back. See [Conversion statuses](/docs/conversion-statuses).
4. **Does your URL resolve from outside your network?** Curl it from somewhere that is not your office.

## Postbacks succeed but users are not credited

The postback reached you and you returned `200`, so the fault is inside your handler. The usual causes:

- The `user_id` you are looking up is not the `userid` you put in the iframe URL.
- You are crediting `{payout_usd}` — dollars — instead of `{currency_amount}`.
- Your dedupe key matches something it should not, so every callback after the first is silently skipped. Log the branch you took.

## Reports do not match

- **Test conversions are excluded** from earnings and reports. If you are counting them yourself, your number will be higher than ours.
- **Reversed conversions stay visible** but their ledger entry is negative. Summing conversion rows without their sign overstates earnings.
- **Reports are per app.** Two apps do not roll up into one line.

## Still stuck

Open a ticket with the `trans_id` and the app ID. Every attempt we made, the URL, your response body and the timing are on our side already — those two values are all we need to find them.
