---
title: Retries & failure
description: The retry schedule, what counts as a failure, and how to read the log.
---

We deliver every postback up to **six times**. Anything that is not a 2xx is a failure and gets retried.

## The schedule

| Attempt | Sent after the previous failure |
|---|---|
| 1 | immediately |
| 2 | 1 minute |
| 3 | 5 minutes |
| 4 | 30 minutes |
| 5 | 2 hours |
| 6 | 6 hours |

Total window: a little under nine hours. After the sixth failure the postback is marked `failed`, you are notified, and it stops. It is not retried again automatically — you re-send it from **Postback Reports** when your endpoint is back.

## What counts as a failure

**Success is a 2xx status code.** That is the whole rule.

| Response | Result |
|---|---|
| `200`, `201`, `204` | Success |
| `301`, `302` | **Failure.** We do not follow redirects |
| `4xx` | Failure |
| `5xx` | Failure |
| Connection refused, DNS failure, TLS error | Failure |
| No response within 15 seconds | Failure |

A publisher returning `500` for an hour must be retried. A publisher whose DNS is down must be retried. Both look identical to "no exception was thrown", which is why the check is on the status code and not on whether the request completed.

**Return `200` even when you rejected the postback as a duplicate.** A duplicate is expected traffic, not an error. Answering a retry with a `409` guarantees five more retries of something you already handled.

## Every attempt is its own row

**Postback Reports** logs one row per attempt, not one row per conversion with a counter. Each row carries:

| Field | Notes |
|---|---|
| URL | With the signature and any `secret`/`key` parameter masked |
| Method | `GET` or `POST`, as configured on the app |
| HTTP status | What your server returned |
| Response body | First 2 KB |
| Duration | Milliseconds |
| Attempt | 1–6 |
| Status | `queued`, `success`, `retrying`, `failed` |

Keeping every attempt is what makes an integration dispute resolvable. One row with `attempts: 6` tells you it failed six times; six rows tell you it returned `500` five times and then `403`, which is a different bug.

## Use the response body

The body of your response is stored and shown in the dashboard. It is the cheapest debugging channel you have:

```php
if (! hash_equals($expected, $_GET['signature'] ?? '')) {
    http_response_code(403);
    exit('signature mismatch for ' . $_GET['trans_id']);   // you will read this in Postback Reports
}
```

Truncated at 2 KB. Do not echo the secret into it — the log is visible to our support staff as well as to you.

## Re-sending by hand

Every logged attempt has a **Re-send** action. It re-resolves the macros against current data, writes a fresh attempt row, and sends.

Rate-limited to **10 re-sends per minute per app**, so it cannot be used to hammer a third party.

Re-sending a reversal re-sends the reversal, not a credit. Macros are resolved from the conversion's current state.

## When we tell you something is wrong

| Signal | Threshold | What happens |
|---|---|---|
| Failure rate for one app | over 20% in 15 minutes | You are notified and the app is flagged in our admin panel |
| Failure rate across all publishers | over 5% in 15 minutes | We are paged. It is us, not you |

If your failure rate is high and you have not heard from us, check the notification address on your account before assuming the postbacks are not arriving.

## If you need to replay a lot of history

Postback Reports filters by app, status and date range. There is no bulk replay API in v1 — for anything larger than a few hundred rows, contact support rather than clicking Re-send four hundred times.
