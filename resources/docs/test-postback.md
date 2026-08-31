---
title: Sending a test postback
description: One button, and how to read what comes back.
---

**Apps → Postback → Send test postback.**

The modal asks for a **user ID** and a **status**. It then sends one signed postback to your configured callback URL. It does not create a conversion, does not touch your balance and does not appear in your reports.

The user ID must be one your site already knows — the same value you pass as `userid` on the wall. A made-up id fails your lookup, not the signature.

Use it to check that your endpoint is reachable and that your signature check passes. Use [test mode](/docs/test-mode) when you want the whole pipeline instead.

## What we send

```txt
GET https://yoursite.com/advault/callback
  ?user_id=<the id you typed>
  &trans_id=OFFERtest-<random>
  &offer_id=0
  &offer_name=AdVault%20test%20offer
  &payout_usd=0.100000          # or -0.100000 on a reversal
  &currency_amount=<payout × your rate>
  &app_id=YOUR_APP_ID
  &country=US
  &status=credited              # or reversed
  &timestamp=<now>
  &signature=<real signature over the real secret>
```

Only the macros in **your** callback URL are sent — the URL is yours, we only substitute into it. The exact values are whatever the panel fills in; the shape is what matters.

The signature is real, computed with your real app secret over the real `trans_id` and `payout_usd`. If your verification passes here it will pass in production.

A reversal uses a fresh `trans_id` and a negative payout. It does not undo a previous test send.

`trans_id` is fresh on every send, so pressing the button twice is two different transactions. To test [deduplication](/docs/idempotency) you need the same `trans_id` twice — send it yourself with curl.

## What you should see

Immediately after, in **Postback Reports**:

| Column | What good looks like |
|---|---|
| HTTP status | `200` |
| Response body | Whatever your handler echoed |
| Duration | Under a few hundred milliseconds |
| Status | `success` |

## What each failure means

| Result | Cause | Fix |
|---|---|---|
| `403` with your own error text | Signature check failed | [Signature verification](/docs/signature-verification). Usually the payout string was reformatted before hashing, or the secret belongs to another app |
| `404` | Callback URL path is wrong | Check the URL in Apps → Postback against your route |
| `405` | Your route only accepts `POST`, the app is set to `GET` | Change the method on the app, or accept both |
| `500` | Your handler threw | The first 2 KB of the body is in the log. Read it |
| `301` / `302` | Your server redirects, e.g. HTTP → HTTPS, or a trailing slash | We do not follow redirects. Configure the final URL |
| Connection error | DNS, firewall, or TLS | Confirm the URL resolves and serves a valid certificate from outside your own network |
| Nothing at all in the log | No callback URL configured | Apps → Postback |

## Testing the parts the button cannot reach

The button sends one credit or one reversal, each with a fresh `trans_id`. Two things it will never test for you, both of which you can test with curl in a minute — see the bottom of [Signature verification](/docs/signature-verification):

1. **A duplicate.** Send the same `trans_id` twice. The balance must move once.
2. **A bad signature.** Change one character of the signature. You must get a `403` and no credit.

If those two pass and the test postback returns `200`, your integration is finished.
