---
title: Quick start
description: Six steps from an empty page to a credited player, each one verifiable on its own.
---

Six steps. Each one can be checked before you start the next, so when something breaks you already know which step owns it.

Steps 1 to 4 are forms in the dashboard. Step 5 is the code you write. Step 6 proves it.

## 1 · Create an app

**Apps → New.** You get an **App ID**, which is public and goes in the wall URL, and a **secret key**, which is private and signs everything.

The secret is shown once. Store it where you keep your other credentials, not in your repository and never in client code.

*Verify:* the app is listed with a status. A new app is `pending` until it is approved, and a pending app renders an "Unavailable" screen instead of offers. That is expected at this stage and does not block the rest of the setup.

## 2 · Embed the wall

```html
<iframe
  src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID"
  width="100%"
  height="800"
  frameborder="0"
  allow="clipboard-write"
  title="Earn coins"></iframe>
```

`USER_ID` is **your** identifier for the player, not one of ours. We echo it back on every conversion and we never learn who it belongs to.

Keep it stable for the life of the account. A conversion can arrive days after the click, and the reward attaches to whatever string was in the URL when the player tapped.

*Verify:* the wall renders inside your page. If the frame is blank, your `sandbox` attribute is missing `allow-scripts allow-same-origin`. [Embedding the wall](/docs/offerwall-iframe) covers the rest of the attributes.

## 3 · Set your currency rate

**Apps → Currency.** How many units of your currency one dollar buys.

At `1000 Coins = $1`, a conversion that earns you $0.35 credits your player 350 Coins. We do the arithmetic and send you both numbers, so your endpoint never reimplements the rate.

*Verify:* the app's currency card shows your name and rate. [Your payout and your rate](/docs/your-payout) shows the full calculation.

## 4 · Add your callback URL

**Apps → Postback.** Macros in `{braces}` are substituted at send time.

```txt
https://yoursite.com/playndgain/callback?user_id={user_id}&trans_id={trans_id}&amount={currency_amount}&payout={payout_usd}&status={status}&sig={signature}
```

The parameter names on the left are yours. The macro on the right is the contract. Every macro is listed in the [macro reference](/docs/macro-reference), which also has a builder that assembles the URL for you.

*Verify:* the URL saves without a validation error. It must be `https://`.

## 5 · Verify, dedupe, credit

The handler. Four things in a fixed order, and the order matters:

```php
// 1. Verify before you touch anything. Hash the payout string exactly as it
//    arrived: 0.130000 is not 0.13, and reformatting it breaks the signature.
$expected = hash('sha256', $_GET['trans_id'] . $_GET['payout'] . PLAYNDGAIN_SECRET);

if (! hash_equals($expected, $_GET['sig'] ?? '')) {
    http_response_code(403);
    exit('invalid signature');
}

$amount = (float) $_GET['amount'];

// 2. Let the unique index dedupe. We retry, so this row WILL be inserted
//    twice. A SELECT-then-INSERT loses the race and pays the player twice.
//    The key is (trans_id, status), never trans_id alone: a reversal reuses
//    the trans_id of the credit it undoes.
try {
    Transaction::create([
        'trans_id' => $_GET['trans_id'],
        'status'   => $_GET['status'],
        'amount'   => $amount,
    ]);
} catch (UniqueConstraintViolationException) {
    exit('ok');   // already handled, and still a 2xx
}

// 3. The amount is NEGATIVE on a reversal. Branch on the sign.
$player = Player::find($_GET['user_id']);

$amount < 0
    ? $player->decrement('coins', (int) abs($amount))
    : $player->increment('coins', (int) $amount);

// 4. Answer 2xx, or we retry it five more times.
echo 'ok';
```

Those four comments are the whole integration. The dedupe and the sign check are in the quick start rather than a later page because between them they account for most of the failures we see, and both of them fail silently.

*Verify:* nothing yet. Step 6 is the verification.

## 6 · Fire a real one

**Apps → Settings → Test mode**, then load your wall with a throwaway `userid` and click the Test Offer at the top.

That is a real conversion through the real pipeline: a click row, fraud rules, a ledger entry, a signed postback to your endpoint. The only difference from production is a flag that keeps it out of your earnings.

*Verify:* **Postback Reports** shows one attempt, status `200`, with whatever your handler echoed in the response body. Your own database has exactly one row and the player has the right number of coins.

Then do the two things the button cannot do for you, both a single curl each and both described in [Testing your integration](/docs/test-mode):

- Send the same `trans_id` twice. The balance must move **once**.
- Negate the payout and the amount, recompute the signature, send. The balance must go **down**.

Publishers skip those two, and those two are what break in production.

## Then

Turn test mode off before you launch, or you ship a one-cent offer to your whole audience. [Going live](/docs/going-live) is the rest of the checklist.
