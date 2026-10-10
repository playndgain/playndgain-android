---
title: Mobile SDKs
description: Show the offerwall natively inside an Android or iOS app, with the same signing, traffic checks and postbacks as the iframe.
---

The Android and iOS SDKs draw the offer list and the player's history as native screens inside your app. Offers open in the phone's browser or app store, and your server is credited by the same signed postback the iframe uses. Nothing about rewards moves into the app.

The SDKs are not in a public package registry yet. To get access, [contact us](/contact) with your App ID.

## What stays the same

- The App ID, the `userid` rules and the signature are the ones on [Signed wall URLs](/docs/signed-urls).
- Every traffic check that runs on the iframe runs on the SDK, through the same server code. An offer the iframe would refuse to a player, the SDK refuses too.
- Postbacks, statuses and reversals are unchanged. Credit your player from the postback, never from the app.

## Sign on your server

The app never holds your secret key. Your server computes the signature for a player it has already authenticated and returns it to the app.

```php
<?php

// After you have checked who the player is.
$sig = hash('sha256', $appId . $userId . getenv('PLAYNDGAIN_SECRET'));

return response()->json(['sig' => $sig]);
```

```js
const crypto = require('crypto');

// After you have checked who the player is.
const sig = crypto
  .createHash('sha256')
  .update(`${appId}${userId}${process.env.PLAYNDGAIN_SECRET}`)
  .digest('hex');

res.json({ sig });
```

## Show the wall

Android:

```kotlin
Playndgain.init(applicationContext, appId = "YOUR_APP_ID")

Playndgain.setUser("4812") { appId, userId, callback ->
    // Ask your server for the signature, then:
    callback.onSignature(sigFromYourServer)
}

Playndgain.show(activity)
```

iOS:

```swift
Playndgain.initialize(appId: "YOUR_APP_ID")

Playndgain.setUser("4812") { appId, userId, completion in
    // Ask your server for the signature, then:
    completion(.success(sigFromYourServer))
}

Playndgain.show()
```

## Options

| Option | Notes |
|---|---|
| `theme` | `app` (your dashboard setting), `light`, `dark` or `system` |
| `language` | `en` or `ar`. Defaults to the device language |
| `balance` | A string shown in the header, such as `1,200 coins` |
| `title` | Replaces the app name in the header |
| `subIds` | Up to five of your own tracking values, echoed back as `{sub1}` … `{sub5}` on the postback |

## Events

| Event | When |
|---|---|
| opened | The first list has loaded |
| closed | The player left the wall |
| reward check | The player came back from an offer. Refresh the balance from your own server |
| error | Initialisation, user, signature, network or availability problems |

The reward check is a prompt to look, not a payment. Rewards arrive by postback, and a postback can come minutes or days after the player returns.

## Advertising ID

Both SDKs have a switch for sending the device advertising ID and a lock for apps directed at children. In this version the switch is stored and nothing is read or sent.

## Testing

Turn on **Test mode** for the app in the dashboard. The test offer is pinned to the top of the list and converts without leaving the wall flow.

Emulators and simulators are refused by the traffic checks on a live app, the same as they are in the iframe. Test with test mode on.

## Without the SDK

A Flutter, React Native or Unity app can load the signed wall URL in a WebView instead. For that to work:

- Enable JavaScript and DOM storage.
- Open `/click/` links, `market:`, `intent:` and `itms-apps:` links, store hosts and any page off the wall host outside the WebView, in the system browser.
- On iOS, handle links that ask for a new window.
