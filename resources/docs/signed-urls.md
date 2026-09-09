---
title: Signed wall URLs
description: When guessable player IDs make signing necessary, and how to compute the signature.
---

By default anyone who knows your App ID can load your wall with any `userid`. For most publishers that is harmless: the wall shows offers, and coins only move when a real conversion completes.

It stops being harmless the moment your identifiers are guessable. If they are sequential integers, anyone can open the wall as player `1`, complete an offer, and the reward lands on a stranger's account.

**Turn on Require signed wall URLs** in **Apps → Security** when your identifiers are enumerable. If they are opaque hashes, you do not need this page.

## The signature

```txt
sig = sha256(appid + userid + secret_key)
```

Plain concatenation, no separators, lower-case hex. Computed on your server, then written into the URL you render.

With signing required, a missing or wrong `sig` gets a `403` and no offers.

## Compute it

```php
<?php

$appId  = 'YOUR_APP_ID';
$userId = (string) $player->id;

$sig = hash('sha256', $appId . $userId . getenv('PLAYNDGAIN_SECRET'));

$url = '{APP_URL}/wall?' . http_build_query([
    'appid'  => $appId,
    'userid' => $userId,
    'sig'    => $sig,
]);
```

```js
import { createHash } from 'node:crypto';

const appId  = 'YOUR_APP_ID';
const userId = String(player.id);

const sig = createHash('sha256')
  .update(appId + userId + process.env.PLAYNDGAIN_SECRET)
  .digest('hex');

const url = `{APP_URL}/wall?appid=${appId}&userid=${encodeURIComponent(userId)}&sig=${sig}`;
```

```python
import hashlib, os
from urllib.parse import urlencode

app_id  = "YOUR_APP_ID"
user_id = str(player.id)

sig = hashlib.sha256(
    (app_id + user_id + os.environ["PLAYNDGAIN_SECRET"]).encode()
).hexdigest()

url = "{APP_URL}/wall?" + urlencode(
    {"appid": app_id, "userid": user_id, "sig": sig}
)
```

## Four rules

**Never compute it in the browser.** Shipping the secret to the client defeats the mechanism entirely, and leaks the key that also signs your postbacks.

**Sign the string you send, decoded.** If you URL-encode `userid`, sign the decoded value. We verify against what we decoded.

**It is not a session.** It authenticates the pairing of an app and a player identifier. There is no timestamp and no expiry, so a player who bookmarks their wall URL keeps a working one.

**Rotating the secret invalidates every URL you have already rendered**, and every postback signature your endpoint is currently checking. Rotate inside a deploy, and not on a Friday.

## Check you got it right

Turn signing on, load your wall with no `sig`, confirm a `403`. Add the signature, confirm the offers come back. If both hold you are finished.
