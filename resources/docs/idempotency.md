---
title: Idempotency
description: Why you must dedupe on trans_id, and why a SELECT first is not deduping.
---

We retry failed deliveries up to six times. Your endpoint **will** see the same `trans_id` more than once. If it credits every time it is called, your users get paid two, three or six times for one offer.

## Dedupe on trans_id

`{trans_id}` is unique per conversion and stable across every retry of that conversion. It is the only field you should key on.

Not `{user_id}` — one user completes many offers. Not `{offer_id}` — one offer is completed by many users, and by the same user again if it is multi-reward. Not the timestamp — it is regenerated on every attempt.

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
    exit('ok');   // already processed — expected traffic, not an error
}

$user->increment('coins', (int) $_GET['currency_amount']);
```

With a unique index on `(trans_id, status)`:

```sql
CREATE UNIQUE INDEX tx_unique ON transactions (trans_id, status);
```

The pair, not `trans_id` alone: a reversal carries the **same** `trans_id` as the credit it undoes, and a unique index on `trans_id` alone would reject the reversal as a duplicate. That is the same failure as ignoring reversals, arriving through a different door.

## Why not check first

This looks equivalent and is not:

```php
// Broken under concurrency.
if (Transaction::where('trans_id', $_GET['trans_id'])->exists()) {
    exit('ok');
}

Transaction::create([...]);
$user->increment('coins', $amount);
```

Two retries arrive in the same millisecond and land on two workers. Both run the `exists()` check. Both see nothing. Both insert. The user is credited twice, and the second credit is invisible until someone reconciles the books months later.

The unique index is the only mechanism that survives concurrency, because settling that race is what a database is for. The `try`/`catch` is the idempotency implementation. The `exists()` check is a lie that passes tests.

If your framework does not expose the constraint violation as a typed exception, an upsert works too:

```sql
INSERT INTO transactions (trans_id, status, amount) VALUES (?, ?, ?)
ON CONFLICT (trans_id, status) DO NOTHING;
```

Then credit the user only when the insert affected a row.

## Credit inside the same transaction

```php
DB::transaction(function () {
    Transaction::create([...]);            // throws on duplicate
    $user->increment('coins', $amount);    // never runs on a duplicate
});
```

If the insert and the balance update are not atomic, a crash between them leaves a recorded transaction that never paid out, or a payout with no record. Both are worse than a retry.

## Always answer 200

A duplicate is not an error condition. Return `200` and a short body:

```txt
HTTP/1.1 200 OK

ok (duplicate)
```

Return a `409` and we treat it as a failure and retry it five more times on the [schedule](/docs/retries-and-failure) — each one a duplicate, each one answered with a `409`.

## What we do on our side

The same thing, one layer up. Inbound conversions carry a unique index on `(network, network transaction id)`, and a duplicate from a network is caught as a constraint violation and logged as `duplicate` rather than raised as an error. Networks retry too.

## Test it

Fire the same signed callback at your endpoint twice, as shown at the bottom of [Signature verification](/docs/signature-verification). The user's balance must change exactly once and both calls must return `200`.
