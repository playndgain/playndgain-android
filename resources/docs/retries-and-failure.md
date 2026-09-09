---
title: Retries and duplicates
description: We deliver up to six times, which is why your endpoint must be safe to call twice.
---

One fact drives this whole page: **a failed delivery is retried up to six times.**

That gives you two jobs. Answer with a 2xx so we stop, and be safe to call again in case we do not. They are the same problem seen from both ends, which is why they are on one page.

## The schedule

| Attempt | Sent after the previous failure |
|---|---|
| 1 | immediately |
| 2 | 1 minute |
| 3 | 5 minutes |
| 4 | 30 minutes |
| 5 | 2 hours |
| 6 | 6 hours |

A little under nine hours in total. After the sixth failure the postback is marked `failed`, you are notified, and it stops. Nothing retries it automatically after that. You re-send it from **Postback Reports** once your endpoint is back.

## What counts as a failure

**Success is a 2xx.** That is the entire rule.

| Response | Result |
|---|---|
| `200`, `201`, `204` | Success |
| `301`, `302` | Failure. We do not follow redirects |
| `4xx` | Failure |
| `5xx` | Failure |
| Connection refused, DNS failure, TLS error | Failure |
| No response within 15 seconds | Failure |

A publisher returning `500` for an hour has to be retried. A publisher whose DNS is down has to be retried. Both look identical to "no exception was thrown on our side", which is why the check is the status code and never whether the request completed.

**Answer `200` even when you rejected the call as a duplicate.** A duplicate is expected traffic, not an error. A `409` guarantees five more retries of something you have already handled correctly.

## Deduplicate on trans_id

`{trans_id}` is unique per conversion and stable across every retry of it. It is the only field to key on.

Not `{user_id}`: one player completes many offers. Not `{offer_id}`: one offer is completed by many players, and by the same player again if it is multi-reward. Not `{timestamp}`: it is regenerated on every attempt.

The key is the **pair** `(trans_id, status)`, never `trans_id` alone. A reversal carries the same `trans_id` as the credit it undoes, so a unique index on `trans_id` alone rejects the reversal as a duplicate and silently leaves the coins in place. That is the same money lost as ignoring reversals, arriving through a different door.

```sql
CREATE UNIQUE INDEX tx_unique ON transactions (trans_id, status);
```

## Let the database settle it

```php
try {
    // The unique index is the dedupe.
    Transaction::create([
        'trans_id' => $_GET['trans_id'],
        'status'   => $_GET['status'],
        'amount'   => (float) $_GET['currency_amount'],
    ]);
} catch (UniqueConstraintViolationException) {
    exit('ok');   // already processed. Expected traffic, not an error
}

$player->increment('coins', (int) $_GET['currency_amount']);
```

If your framework does not expose the violation as a typed exception, an upsert does the same job:

```sql
INSERT INTO transactions (trans_id, status, amount) VALUES (?, ?, ?)
ON CONFLICT (trans_id, status) DO NOTHING;
```

Then credit the player only when the insert affected a row.

## Why checking first does not work

This looks equivalent to the above. It is not:

```php
// Broken under concurrency.
if (Transaction::where('trans_id', $_GET['trans_id'])->exists()) {
    exit('ok');
}

Transaction::create([...]);
$player->increment('coins', $amount);
```

Two retries arrive in the same millisecond and land on two workers. Both run the `exists()` check. Both see nothing. Both insert. The player is credited twice, and the second credit is invisible until somebody reconciles the books months later.

The unique index is the only mechanism that survives concurrency, because settling that race is what a database is for. The `try`/`catch` is the implementation. The `exists()` check is a version that passes every test you write for it and fails in production.

## Credit inside the same transaction

```php
DB::transaction(function () {
    Transaction::create([...]);              // throws on a duplicate
    $player->increment('coins', $amount);    // never runs on a duplicate
});
```

If the insert and the balance change are not atomic, a crash between them leaves either a recorded transaction that never paid out, or a payout with no record. Both are worse than a retry.

## Every attempt is its own row

**Postback Reports** logs one row per attempt, not one row per conversion with a counter:

| Field | Notes |
|---|---|
| URL | With the signature and any `secret` or `key` parameter masked |
| Method | `GET` or `POST`, as configured on the app |
| HTTP status | What your server returned |
| Response body | First 2KB |
| Duration | Milliseconds |
| Attempt | 1 to 6 |
| Status | `queued`, `success`, `retrying`, `failed` |

Keeping every attempt is what makes a dispute resolvable. One row saying `attempts: 6` tells you it failed six times. Six rows tell you it returned `500` five times and then `403`, which is a different bug with a different fix.

## Use the response body

It is stored and shown in the dashboard, which makes it the cheapest debugging channel you have:

```php
if (! hash_equals($expected, $_GET['signature'] ?? '')) {
    http_response_code(403);
    exit('signature mismatch for ' . $_GET['trans_id']);   // you will read this in Postback Reports
}
```

Truncated at 2KB. Never echo the secret into it: the log is visible to our support staff as well as to you.

## Re-sending by hand

Every logged attempt has a **Re-send** action. It re-resolves the macros against current data, writes a fresh attempt row and sends. Limited to 10 per minute per app so it cannot be pointed at a third party.

Re-sending a reversal sends the reversal, not a credit. Macros resolve from the conversion's current state.

There is no bulk replay API. For more than a few hundred rows, talk to support rather than clicking Re-send four hundred times.

## When we tell you something is wrong

| Signal | Threshold | What happens |
|---|---|---|
| Failure rate for one app | over 20% in 15 minutes | You are notified and the app is flagged for us |
| Failure rate across all publishers | over 5% in 15 minutes | We are paged. It is us, not you |

If your failure rate is high and you have heard nothing, check the notification address on your account before assuming the postbacks are not arriving.

## Test it

Fire the same signed callback at your endpoint twice, using the curl at the bottom of [Verifying the signature](/docs/signature-verification). The player's balance must change exactly once, and both calls must return `200`.

## What we do on our side

The same thing, one layer up. Inbound conversions carry a unique index on the demand partner and their transaction ID, and a duplicate is caught as a constraint violation and logged as `duplicate` rather than raised as an error.

Partners retry too. Nobody in this chain assumes a delivery happened once.
