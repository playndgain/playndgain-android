---
title: Testing your integration
description: Test mode, the test offer, the test postback button, and the two things only curl can check.
---

You can test the whole pipeline yourself at two in the morning without asking anybody. There are three tools, and they reach progressively further into the system.

| Tool | Tests | Creates a conversion |
|---|---|---|
| **Send test postback** | Your endpoint is reachable and your signature check passes | No |
| **Test mode + Test Offer** | The entire pipeline, end to end | Yes, flagged as a test |
| **curl** | Duplicates and reversals | No |

Use all three, in that order. The third is not optional: it covers the two failures that reach production.

## Send test postback

**Apps → Postback → Send test postback.**

The modal asks for a user ID and a status, then sends one signed postback to your callback URL. It does not create a conversion, does not touch your balance and does not appear in your reports.

Use a user ID your own site already knows, the same value you pass as `userid` on the wall. A made-up one fails your lookup rather than the signature, which tells you nothing.

```txt
GET https://yoursite.com/playndgain/callback
  ?user_id=<the id you typed>
  &trans_id=OFFERtest-<random>
  &offer_id=0
  &offer_name=PLAYNDGAIN%20test%20offer
  &payout_usd=0.100000          # or -0.100000 on a reversal
  &currency_amount=<payout × your rate>
  &app_id=YOUR_APP_ID
  &country=US
  &status=credited              # or reversed
  &timestamp=<now>
  &signature=<real signature over your real secret>
```

Only the macros in **your** callback URL are sent. The URL is yours and we only substitute into it, so the exact values above are illustrative and the shape is the point.

The signature is real, computed with your real app secret. If your check passes here it passes in production.

A reversal from this button uses a fresh `trans_id` and a negative payout. It does not undo a previous send, and pressing the button twice gives you two different transactions.

## Test mode and the Test Offer

**Apps → Settings → Test mode.**

| Behaviour | Normal | Test mode |
|---|---|---|
| `country` URL parameter | Ignored | **Honoured**, so you can preview any geo |
| Test Offer | Absent | Pinned to the top of the wall, payout `$0.01` |
| Real offers | Shown | Still shown |
| Conversions from the Test Offer | | Flagged as test, excluded from earnings and reports |

Everything else is unchanged. Real offers still work and real conversions still pay.

Clicking the Test Offer fires a **real end-to-end conversion**: a click row, fraud rules, a conversion row, a ledger entry, a signed outbound postback. It is not a mock. The only difference is the flag that keeps it out of your money.

So it exercises exactly the things that break in a first integration:

- Whether your `userid` arrived intact and came back the same
- Whether your signature check passes against a real signature
- Whether your endpoint returned a 2xx
- What your endpoint's response body actually said

## Previewing another country

With test mode on:

```txt
{APP_URL}/wall?appid=YOUR_APP_ID&userid=qa-1&country=DE
```

ISO-3166-1 alpha-2. This is how you check that a geo you care about has inventory before you launch there rather than after.

In production the parameter is ignored and the country comes from the player's IP, because an offer's eligibility and payout are geo-bound by the advertiser's contract.

## The working loop

1. Turn test mode on
2. Load your wall with a throwaway `userid` such as `qa-1`
3. Click the Test Offer
4. Watch **Postback Reports** for the attempt and read the response body your server returned
5. Check your own database: one row, right player, right amount

Then the two steps the buttons cannot do for you, because both need a `trans_id` you control. Use the curl block at the bottom of [Verifying the signature](/docs/signature-verification):

6. **Send the same `trans_id` twice.** The balance must move once and both calls must return `200`. That is your [dedupe](/docs/retries-and-failure)
7. **Negate the payout and the amount, recompute the signature, send.** The balance must go **down**. That is your [reversal branch](/docs/chargebacks-reversals)

Steps 6 and 7 are the ones publishers skip, and they are the two that fail in production.

## Reading a failure

| Result | Cause | Fix |
|---|---|---|
| `403` with your own error text | Signature check failed | [Verifying the signature](/docs/signature-verification). Usually the payout string was reformatted before hashing, or the secret belongs to another app |
| `404` | The callback path is wrong | Check the URL in Apps → Postback against your route |
| `405` | Your route is `POST` only and the app is set to `GET` | Change the method on the app, or accept both |
| `500` | Your handler threw | The first 2KB of the body is in the log. Read it |
| `301` or `302` | Your server redirects, often HTTP to HTTPS or a trailing slash | We do not follow redirects. Configure the final URL |
| Connection error | DNS, firewall or TLS | Confirm the URL resolves and serves a valid certificate from outside your own network |
| Nothing in the log at all | No callback URL configured | Apps → Postback |

## Turn it off before you launch

The Test Offer is visible to every player of an app in test mode and it pays `$0.01`. Leaving it on ships a one-cent offer to your whole audience. It is the first item on [Going live](/docs/going-live).
