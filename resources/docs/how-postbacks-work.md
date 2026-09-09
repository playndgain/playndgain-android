---
title: How the callback works
description: One conversion, from the advertiser's confirmation to the row in your database.
---

A callback is an HTTP request we make to a URL you configure. It is the only way you learn that a player earned something.

## The path one conversion takes

```txt
Advertiser confirms the action
        │
        ▼
Demand partner  ──►  POST /postback/{driver}        inbound, ours
        │
        │  1. log the raw request, before anything can fail
        │  2. verify the partner's IP and signature
        │  3. resolve the click into app, publisher, end user
        │  4. run post-conversion fraud rules
        │  5. write the conversion and its ledger entry
        ▼
PLAYNDGAIN  ──►  GET https://yoursite.com/…         outbound, yours
        │
        │  6. resolve macros against the conversion
        │  7. sign, send, log the attempt and your response
        ▼
Your server  ──►  2xx
```

Steps 1 to 5 usually finish inside a second. Step 6 is queued: outbound delivery runs on its own workers, so one slow endpoint never delays another publisher's money.

## Configure it

**Apps → Postback.**

| Field | Values | Notes |
|---|---|---|
| Callback URL | any `https://` URL | Macros in `{braces}` are substituted at send time |
| Method | `GET` or `POST` | `POST` sends the same macros as a form body |

Leaving the URL blank is a valid configuration. No job is queued and no error is raised. You are choosing to read the dashboard instead of being pushed to.

## What arrives

One request per conversion **event**, not per conversion. A conversion that is credited and later reversed produces **two**: a credit, then a reversal carrying the same `trans_id` and a negative amount.

```txt
GET https://yoursite.com/playndgain/callback
  ?user_id=USER_123
  &trans_id=OFFER19624243-Gw2CpY
  &payout=0.091000
  &amount=91.00
  &country=US
  &status=credited
  &sig=a3f9…
```

Every macro is in the [macro reference](/docs/macro-reference).

## What we need back

**A 2xx.** Nothing else counts as success.

| Response | Result |
|---|---|
| `200`, `201`, `204` | Success |
| `301`, `302` | Failure. We do not follow redirects |
| `4xx`, `5xx` | Failure |
| No response within 15 seconds | Failure |

A body is optional. We store the first 2KB of it and show it in Postback Reports, which makes it the cheapest debugging channel you have. Echo your own error text and read it in the dashboard.

If crediting a player is slow on your side, answer `200` first and do the work asynchronously. Do not hold the request open.

## The three rules

1. **Verify the signature before you trust a single value.** [Verifying the signature](/docs/signature-verification)
2. **Refuse to act twice on the same transaction.** We retry, so you will see it twice. [Retries and duplicates](/docs/retries-and-failure)
3. **Handle a negative amount.** [Reversals](/docs/chargebacks-reversals)

## Every attempt is logged

**Postback Reports** keeps one row per attempt: the URL we called, the status, your response body, the duration, and which attempt it was.

The stored URL has the signature and any `secret` or `key` parameter masked. Those logs are visible to you and to our support staff, and an unredacted one would be a credential leak between accounts.

Any attempt can be re-sent from that screen. It re-resolves the macros against current data, writes a fresh attempt row and sends. Limited to 10 re-sends per minute per app.
