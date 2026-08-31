# 04 — Conversions & Postbacks

The money pipeline. Everything here is either correct or the business is broken.

---

## 1. Inbound: Network → AdVault

### Endpoint

```
GET|POST  https://api.advault.io/postback/{driver}
```

One route, driver resolved from the path segment. Each network is given its own URL and configures it in their dashboard.

### Order of operations

```
1.  Log the raw request to incoming_postbacks       ← FIRST, always, before anything can fail
2.  Resolve the network by driver key                → 404, result=error
3.  Check source IP against networks.postback_ips    → 403, result=rejected
    (`allow_any_postback_ip` skips this gate; empty list does not)
4.  driver->verifyPostback()                         → 403, result=rejected
5.  driver->parsePostback() → PostbackDTO            → 422, result=error
6.  Check networks.status is active                  → 403, result=rejected
    (a reversal skips this gate; see below)
7.  Branch on status:
       credited  → creditConversion()
       reversed  → reverseConversion()
       rejected  → rejectConversion()
       pending   → record, take no money action
8.  Return 200 with the body the network expects
```

**Step 6 exempts reversals, and that is why it sits after the parse.** The gate
is there so a seeded network nobody has configured cannot *earn*. A chargeback
only ever takes money off a conversion already recorded under the same network,
so a network that was never switched on has nothing for one to touch — while a
network disabled *after* it ran is exactly when chargebacks arrive, and refusing
them leaves the publisher holding money the advertiser already took back. The
gates that authenticate the sender, steps 3 and 4, are upstream of it and apply
to a reversal like anything else.

Chargebacks the gate refused before it was narrowed are applied from their log
rows by `php artisan postbacks:replay-chargebacks` (`--dry-run` first, `--driver=`
to scope it). It re-parses each stored payload through the same driver and hands
the reversals to the same processor, so a replayed chargeback lands exactly as a
live one would — the conversion flips to `reversed`, a `chargeback` entry debits
the publisher, and their callback URL gets the reversal postback. Refused credits
are left refused: a disabled network still must not earn.

**Step 1 is first for a reason.** When a network says "we sent you 400 conversions yesterday", `incoming_postbacks` is the only thing that can confirm or refute it. A log written after validation only records the requests that were already fine.

```php
public function handle(Request $request, string $driver): Response
{
    $log = IncomingPostback::create([
        'driver'  => $driver,
        'method'  => $request->method(),
        'query'   => $request->query(),
        'body'    => $request->except(['password', 'secret', 'key']),
        'headers' => Arr::except($request->headers->all(), ['authorization', 'cookie']),
        'ip'      => $request->ip(),
        'result'  => 'error',
    ]);

    // … validation, then $log->update([...]) at each exit point
}
```

### Idempotency

The one thing that must never be wrong.

```php
DB::transaction(function () use ($dto, $network, $click, $log) {
    try {
        $conversion = Conversion::create([
            'trans_id'       => TransactionId::generate($dto->offerExternalId),
            'network_txid'   => $dto->networkTxId,
            'network_id'     => $network->id,
            // …
        ]);
    } catch (UniqueConstraintViolationException) {
        // Already processed. Networks retry; this is expected traffic, not an error.
        $log->update(['result' => 'duplicate']);
        return;
    }

    LedgerEntry::createEarning($conversion);
    $conversion->publisher->increment('balance', $conversion->payout);

    DispatchPublisherPostback::dispatch($conversion)->onQueue('postbacks');
});
```

**Why a caught constraint violation and not `if (Conversion::where(...)->exists())`:**

Two workers process a duplicated postback in the same millisecond. Both run the `exists()` check. Both see nothing. Both insert. The publisher is paid twice, and the second payment is invisible until a reconciliation months later. The unique index on `(network_id, network_txid)` is the only mechanism that survives concurrency, because settling that race is what a database is for.

The `try/catch` is the idempotency implementation. The `exists()` check is a lie that passes tests.

### Unresolvable clicks

Some postbacks arrive with a `click_id` that matches nothing — a network's test fire, a truncated subid, an offer clicked before a migration.

- `result = unknown_click`, conversion **not** created, no money moves.
- The row stays in `incoming_postbacks` and appears in an admin **Unmatched postbacks** view.
- Admin can manually attach it to a click, which then runs the normal credit path.

Silently discarding these is how you end up telling a publisher "we never got it" when the log says otherwise.

### Conversion status on arrival

| Condition | Status |
|---|---|
| Passes all post-conversion fraud rules | `approved` |
| Fails a `flag`-action rule | `pending` — sits in the admin review queue |
| Fails a `block`-action rule | `rejected` — no ledger entry, no postback |
| Network explicitly sent a pending state | `pending` |

Only `approved` writes a ledger entry.

---

## 2. Outbound: AdVault → Publisher

### Macro set

Publishers put these in their callback URL. This table is the public contract and is reproduced verbatim in the developer docs.

| Macro | Type | Example | Notes |
|---|---|---|---|
| `{user_id}` | string | `USER_123` | Exactly what was passed as `userid` on the wall |
| `{trans_id}` | string | `OFFER19624243-Gw2CpY` | Unique per conversion. **Use this for your own dedupe.** |
| `{offer_id}` | int | `19624243` | |
| `{offer_name}` | string | `Badoo%3A%20Dating` | URL-encoded |
| `{payout_usd}` | decimal | `0.130000` | Publisher's share, USD, 6 dp |
| `{currency_amount}` | decimal | `130.00` | `payout_usd × currency_rate` |
| `{app_id}` | int | `10042` | |
| `{country}` | string | `US` | ISO-2 |
| `{ip}` | string | `167.224.159.233` | End user's IP at click time |
| `{status}` | string | `credited` \| `reversed` | |
| `{timestamp}` | int | `1754179200` | Unix seconds |
| `{signature}` | string | `a3f9…` | See below |

### Signature

```
signature = sha256( trans_id + payout_usd + app_secret )
```

`payout_usd` is the string exactly as it appears in the URL, six decimal places, no thousands separator. On a reversal it carries the minus sign and the signature covers it, so a reversal cannot be replayed as a credit.

Publisher-side verification, documented with a copyable snippet per language:

```php
$expected = hash('sha256', $_GET['trans_id'] . $_GET['payout_usd'] . YOUR_SECRET);

if (! hash_equals($expected, $_GET['signature'])) {
    http_response_code(403);
    exit('invalid signature');
}
```

### Delivery job

```php
class DispatchPublisherPostback implements ShouldQueue
{
    public int $tries = 6;
    public int $timeout = 20;

    public array $backoff = [60, 300, 1800, 7200, 21600];   // 1m, 5m, 30m, 2h, 6h

    public function handle(): void
    {
        $app = $this->conversion->app;

        if (blank($app->postback_url)) {
            return;   // publisher polls the dashboard instead — legitimate configuration
        }

        $url = MacroResolver::resolve($app->postback_url, $this->conversion, $this->kind);

        $log = OutgoingPostback::create([
            'conversion_id' => $this->conversion->id,
            'app_id'        => $app->id,
            'kind'          => $this->kind,
            'url'           => UrlRedactor::redact($url),
            'method'        => $app->postback_method,
            'attempt'       => $this->attempts(),
            'status'        => 'queued',
        ]);

        $started = hrtime(true);

        $response = Http::timeout(15)
            ->withUserAgent('AdVault-Postback/1.0')
            ->send($app->postback_method, $url);

        $log->update([
            'http_status'   => $response->status(),
            'response_body' => Str::limit($response->body(), 2000),
            'duration_ms'   => (int) ((hrtime(true) - $started) / 1e6),
            'status'        => $response->successful() ? 'success' : 'retrying',
        ]);

        if (! $response->successful()) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 21600);
        }
    }

    public function failed(Throwable $e): void
    {
        OutgoingPostback::where('conversion_id', $this->conversion->id)
            ->latest()->first()?->update(['status' => 'failed', 'error' => $e->getMessage()]);

        PostbackFailureAlert::notify($this->conversion);
    }
}
```

**Success is `2xx` only.** Not "the request completed" — a publisher returning `500` for an hour must be retried, and a publisher whose DNS is down must be retried. Both look like "no exception thrown" if you only check for transport errors.

**Every attempt writes its own row.** One row per conversion with an incrementing counter loses the history of what each attempt actually returned, which is exactly what you need during an integration dispute.

### Redaction

`OutgoingPostback.url` is stored with the `{signature}` value and any `secret`/`key` query parameter masked. Postback logs are visible to the publisher in their own panel, and to admins across all publishers — an unredacted log is a credential leak between accounts.

### Manual re-fire

Admin and publisher both get a **Re-send** action on a failed postback. It re-resolves macros from current data, creates a fresh attempt row, and sends. Rate-limited to 10 per minute per app so it cannot be used to hammer a third party.

---

## 3. Chargebacks & Reversals

A partner reverses a conversion when the advertiser rejects it — fraud, a refunded purchase, a duplicate install.

```php
public function reverseConversion(PostbackDTO $dto, Network $network): void
{
    $conversion = Conversion::where('network_id', $network->id)
        ->where('network_txid', $dto->networkTxId)
        ->first();

    if (! $conversion || $conversion->status === 'reversed') {
        return;   // idempotent: reversing a reversal is a no-op
    }

    DB::transaction(function () use ($conversion) {
        $conversion->update(['status' => 'reversed', 'reversed_at' => now()]);

        LedgerEntry::createChargeback($conversion);          // amount = -payout
        $conversion->publisher->decrement('balance', $conversion->payout);
    });

    DispatchPublisherPostback::dispatch($conversion, kind: 'reversal')->onQueue('postbacks');
}
```

### What the publisher receives

Same URL, same macros, with:

- `{status}` = `reversed`
- `{payout_usd}` = `-0.130000`
- `{currency_amount}` = `-130.00`
- `{trans_id}` = **the original transaction ID**, so they can find what to reverse
- `{signature}` recomputed over the negative payout

Publishers must handle negative amounts. This is stated in the docs in bold, in the quick start, with a worked example — it is the single most common integration mistake in this vertical, and the failure mode is a publisher paying out coins they never got paid for.

### Reversal after the hold clears

The money may already be withdrawn. The ledger goes negative; the balance goes negative.

- The publisher sees a negative balance and a clear explanation in their transactions view.
- Future earnings pay it down automatically — no separate collection mechanism.
- A negative balance older than 30 days raises an admin alert.
- **We do not automatically claw back from a paid withdrawal.** That is a human decision, taken in the admin panel, logged in `audit_logs`.

---

## 4. Transaction IDs

```php
final class TransactionId
{
    public static function generate(?string $offerExternalId = null): string
    {
        return 'OFFER' . ($offerExternalId ?? '') . '-' . Str::random(16);
    }
}
```

Prefixed and readable in support conversations, random suffix so nothing about volume or ordering is inferable. `trans_id` carries a unique index; a collision at 16 random alphanumerics is not a practical concern, and the index catches it if it ever happens.

---

## 5. Queue Layout

| Queue | Contents | Priority | Workers |
|---|---|---|---|
| `postbacks` | Publisher postback delivery | Highest | 4 |
| `default` | Notifications, exports, alerts | Normal | 2 |
| `sync` | Network catalogue pulls | Lowest | 1 |

Horizon config in [11-infrastructure.md](11-infrastructure.md). The separation matters: a network sync that takes ten minutes must never sit in front of a conversion postback.

---

## 6. Monitoring

| Signal | Threshold | Action |
|---|---|---|
| Postback failure rate per app | > 20% over 15 min | Notify the publisher, flag the app in admin |
| Postback failure rate global | > 5% over 15 min | Page the admin — it is us, not them |
| `postbacks` queue depth | > 1000 | Alert |
| Conversions per hour | Zero for 2 h during normal hours | Alert — a silent network integration break |
| `unknown_click` rate | > 5% of inbound | Alert — usually a broken subid macro after a driver change |
| Duplicate rate per network | > 30% | Informational; some networks always retry |

---

## 7. Tests

The money-path suite. These are the tests that justify the whole test suite existing.

- Same `(network_id, network_txid)` posted twice → one conversion, one ledger entry, second logged as `duplicate`.
- **Concurrent** duplicate (two workers, same payload) → still one conversion. Run against a real database, not SQLite in-memory, since it is the constraint under test.
- Rev-share math: `revenue 0.130000` at `70%` → `payout 0.091000` exactly, asserted to 6 dp.
- Currency math: `payout 0.091` at `rate 1000` → `currency_amount 91.00`.
- Reversal → negative ledger entry, balance returns to its pre-conversion value to the cent.
- Reversing an already-reversed conversion → no second entry.
- Signature verification: correct signature passes; a mutated payout fails; a reversal's negative payout produces a different signature than the credit.
- Retry schedule: a `500` response releases with the expected backoff and writes a `retrying` row; the sixth failure marks `failed`.
- A publisher with no `postback_url` produces no job and no error.
- Postback log URL contains no raw signature or secret.
- Unmatched postback → `result=unknown_click`, no conversion, appears in the admin queue.
