---
title: How postbacks work
description: The lifecycle of one conversion, from the advertiser to your database.
---

A postback is an HTTP request we make to a URL you configure. It is the only way you learn that a user earned something.

## The lifecycle

```txt
Advertiser confirms the conversion
        │
        ▼
Network  ──►  POST /postback/{driver}          inbound, ours
        │
        │  1. log the raw request, before anything can fail
        │  2. verify the network's IP and signature
        │  3. resolve the click → app, publisher, end user
        │  4. run post-conversion fraud rules
        │  5. write the conversion + ledger entry
        ▼
AdVault  ──►  GET https://yoursite.com/…       outbound, yours
        │
        │  6. resolve macros against the conversion
        │  7. sign, send, log the attempt and your response
        ▼
Your server  ──►  2xx
```

Steps 1–5 usually take under a second from the network's call. Step 6 is queued: outbound delivery runs on its own worker pool so a slow endpoint of yours never blocks another publisher's conversion.

## Configure it

**Apps → Postback.**

| Field | Values | Notes |
|---|---|---|
| Callback URL | any `https://` URL | Macros in `{braces}` are substituted at send time |
| Method | `GET` or `POST` | `POST` sends the same macros as a form body |

Leaving the callback URL blank is a valid configuration. No job is queued and no error is raised — you are polling the dashboard instead of being pushed to.

## What arrives

One request per conversion event. A conversion that is credited and later reversed produces **two** requests: a credit, then a reversal carrying the same `trans_id` and a negative amount.

```txt
GET https://yoursite.com/advault/callback
  ?user_id=USER_123
  &trans_id=OFFER19624243-Gw2CpY
  &payout=0.091000
  &amount=91.00
  &country=US
  &status=credited
  &sig=a3f9…
```

Every available macro is listed in the [macro reference](/docs/macro-reference).

## What we expect back

**A 2xx status code.** Nothing else counts.

- A `3xx` is a failure. We do not follow redirects.
- A `4xx` or `5xx` is a failure and we retry on the schedule in [Retries & failure](/docs/retries-and-failure).
- A body is optional. We store the first 2 KB of it and show it to you in Postback Reports, which makes it the cheapest debugging channel you have — echo your own error text and read it in the dashboard.

Timeout is 15 seconds. An endpoint that takes longer is treated as a failure, so do the work asynchronously if crediting a user is slow on your side.

## The three rules

1. **Verify the signature before you trust anything.** [Signature verification](/docs/signature-verification).
2. **Deduplicate on `trans_id`.** We retry. You will see the same transaction more than once. [Idempotency](/docs/idempotency).
3. **Handle negative amounts.** [Chargebacks & reversals](/docs/chargebacks-reversals).

## Every attempt is logged

**Postback Reports** shows one row per attempt: the URL we called, the HTTP status, the response body, the duration, and which attempt number it was.

The stored URL has the `{signature}` value and any `secret` or `key` parameter masked. Logs are visible to you and to our admins, and an unredacted log is a credential leak between accounts.

You can re-send any attempt from that screen. It re-resolves macros against current data, writes a fresh attempt row and sends. Limited to 10 re-sends per minute per app.
