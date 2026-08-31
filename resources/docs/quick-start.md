---
title: Quick start
description: Live in ten minutes. Six steps, each one verifiable.
---

Six steps. Each one is verifiable on its own, so when something breaks you know which step to look at.

## 1 · Create an app

Dashboard → **Apps** → **New**. You get an **App ID** and a **Secret key**.

The secret is shown once. Store it in your server's environment — never in client-side code, never in a repository, never in the iframe URL.

## 2 · Embed the offerwall

```html
<iframe
  src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID"
  width="100%" height="800" frameborder="0"
  allow="clipboard-write"></iframe>
```

`USER_ID` is **your** identifier for the user. We echo it back on every conversion. Keep it stable — if it changes, the reward goes to the wrong account, and it goes there silently.

Use an internal primary key or an opaque hash. Do not use an email address or anything else the user can change.

## 3 · Set your currency rate

**Apps → Currency.** The rate is how many units of your currency one dollar buys.

If `1000 Coins = $1`, a $0.50 offer at a 70% revenue share credits your user:

```txt
0.50  ×  0.70  ×  1000  =  350 Coins
```

## 4 · Add your callback URL

**Apps → Postback.** Put your endpoint in, with the macros you need:

```txt
https://yoursite.com/advault/callback?user_id={user_id}&trans_id={trans_id}&amount={currency_amount}&payout={payout_usd}&status={status}&sig={signature}
```

Every macro is listed in the [macro reference](/docs/macro-reference). The four you cannot integrate without are `{user_id}`, `{trans_id}`, `{currency_amount}` and `{signature}`.

## 5 · Verify the signature and credit the user

```php
<?php

$expected = hash('sha256', $_GET['trans_id'] . $_GET['payout'] . ADVAULT_SECRET);

if (! hash_equals($expected, $_GET['sig'])) {
    http_response_code(403);
    exit('invalid signature');
}

// Dedupe: we retry failed callbacks, so you WILL see the same trans_id twice.
// Key on (trans_id, status), never trans_id alone — a reversal carries the
// SAME trans_id as the credit it undoes.
if (Transaction::where('trans_id', $_GET['trans_id'])->where('status', $_GET['status'])->exists()) {
    exit('ok');
}

// Amount is NEGATIVE on a reversal. Handle it.
User::find($_GET['user_id'])->increment('coins', (int) $_GET['amount']);

Transaction::create([
    'trans_id' => $_GET['trans_id'],
    'status'   => $_GET['status'],
    'amount'   => $_GET['amount'],
]);

echo 'ok';   // we need a 2xx, or we retry
```

The dedupe check and the negative-amount comment are here, in the quick start, rather than buried three pages down. Those two mistakes account for most integration failures on this platform.

Two refinements this snippet leaves out for readability, both of which you want in production: catch the unique-constraint violation instead of checking with `exists()`, which loses the race between two simultaneous retries, and wrap the insert and the balance change in one transaction. [Idempotency](/docs/idempotency) has both, and the complete handlers on [Signature verification](/docs/signature-verification) ship with them.

Complete, runnable versions in five languages are on [Signature verification](/docs/signature-verification).

## 6 · Test it

**Apps → Postback → Send test postback.** Then open **Postback Reports** and read the response your server returned.

For a full end-to-end run — a real click, a real conversion, a real ledger entry — switch the app to [test mode](/docs/test-mode) and complete the test offer on the wall.

## The three things that break integrations

1. **You did not dedupe on `trans_id`.** We retry failed deliveries up to six times. Users get credited twice.
2. **You ignored negative amounts.** A reversal arrives as a negative payout on the original transaction ID. Ignore it and you are paying out rewards on revenue you never received. Read [Chargebacks & reversals](/docs/chargebacks-reversals).
3. **You did not return a 2xx.** Anything else, including a redirect, counts as a failure and we retry.
