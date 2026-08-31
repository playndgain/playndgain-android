---
title: Signed URLs
description: HMAC on the wall URL — when you need it and how to compute it.
---

By default anyone who knows your App ID can load your wall with any `userid`. For most publishers that is harmless: the wall shows offers, and rewards only move when a real conversion completes.

It stops being harmless when your `userid` is guessable. If your identifiers are sequential integers, anyone can open a wall as user `1`, complete an offer, and the reward lands on someone else's account.

**Turn on Require signed wall URLs** in **Apps → Security** when your user identifiers are enumerable.

## The signature

```txt
sig = sha256(appid + userid + secret_key)
```

Plain concatenation, no separators, lower-case hex. Computed **on your server**, then written into the iframe URL you render.

With the app active and signing required, a request with a missing or wrong `sig` gets a `403` and no offers.

## Compute it

```php
<?php

$appId  = 'YOUR_APP_ID';
$userId = (string) $user->id;

$sig = hash('sha256', $appId . $userId . getenv('ADVAULT_SECRET'));

$url = '{APP_URL}/wall?' . http_build_query([
    'appid'  => $appId,
    'userid' => $userId,
    'sig'    => $sig,
]);
```

```js
import { createHash } from 'node:crypto';

const appId  = 'YOUR_APP_ID';
const userId = String(user.id);

const sig = createHash('sha256')
  .update(appId + userId + process.env.ADVAULT_SECRET)
  .digest('hex');

const url = `{APP_URL}/wall?appid=${appId}&userid=${encodeURIComponent(userId)}&sig=${sig}`;
```

```python
import hashlib, os
from urllib.parse import urlencode

app_id  = "YOUR_APP_ID"
user_id = str(user.id)

sig = hashlib.sha256(
    (app_id + user_id + os.environ["ADVAULT_SECRET"]).encode()
).hexdigest()

url = "{APP_URL}/wall?" + urlencode(
    {"appid": app_id, "userid": user_id, "sig": sig}
)
```

## Rules

- **Never compute the signature in the browser.** Shipping the secret to the client defeats the entire mechanism and leaks the key that also signs your postbacks.
- **Sign the exact string you send.** If you URL-encode `userid`, sign the *decoded* value — we verify against what we decoded.
- **The signature is not a session.** It authenticates the pairing of an app and a user identifier. It does not expire and it carries no timestamp; a user who bookmarks their wall URL keeps a working one.
- **Rotating the secret invalidates every signed URL** you have already rendered, and every postback signature your endpoint is checking. Rotate during a deploy, not on a Friday.

## Verifying you got it right

Turn signing on, load your wall without `sig`, and confirm you get a `403`. Then load it with the signature and confirm the offers come back. If both hold, you are done.
