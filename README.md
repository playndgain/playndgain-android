# Playndgain Android SDK

A native offerwall for Android apps: an offer list, offer details and the player's history, drawn with native views. Offers open inside the app where they can (see below). Rewards reach your server by the same signed postback the iframe wall uses.

- minSdk 23, compileSdk 35
- Dependencies: `androidx.recyclerview`. No OkHttp, no image library, no Play Services.
- Your secret key never goes into the app.

## Install

Not published yet. Until it is, build the AAR locally:

```
./gradlew :sdk:assembleRelease
# sdk/build/outputs/aar/sdk-release.aar
```

Maven Central coordinates once published: `com.playndgain:offerwall:1.1.0`.

## Use

```kotlin
Playndgain.init(applicationContext, appId = "YOUR_APP_ID")

Playndgain.setUser("4812") { appId, userId, callback ->
    // Ask your own server for sha256(appId + userId + secret), then:
    callback.onSignature(sigFromYourServer)
    // or callback.onFailure(error)
}

Playndgain.setListener(object : PlayndgainListener {
    override fun onRewardCheck() { /* refresh the balance from your server */ }
    override fun onError(error: PlayndgainError) { }
})

Playndgain.show(activity, PlayndgainOptions(subIds = listOf("spring_promo")))
```

Call `Playndgain.setListener(null)` when the screen that set it is destroyed; the listener is held strongly.

`onRewardCheck` is a prompt to look, not a payment. Credit the player from your postback.

### How an offer opens

"Start this offer" shows a short loading state while the SDK follows the offer's link in a WebView the player does not see yet. The link is requested once.

- A Google Play app: the Play Store app opens on its listing, or the listing on the web if the Play Store is missing.
- Another app's link: the system opens that app.
- A web page that has finished loading: the same WebView rises as a sheet with a Close button. Back goes back inside the page first, then closes it.
- Nothing settled within about 8 seconds: the loading card says the offer is still opening, with Keep waiting (waits on the same load, never a second click) and Close.

`onRewardCheck` fires once per offer: when the player closes the web sheet, or when the app comes back after the offer left it. No `<queries>` entry is needed in your manifest.

`setUser` with an id outside the allowed alphabet forgets the previous user as well and reports `INVALID_USER_ID`, so `show` then reports `USER_NOT_SET` instead of opening the last player's wall.

### Arabic and right-to-left

The Arabic wall mirrors only when your app declares RTL support. The SDK does not set it for you, because a library value would conflict with an app that sets it to false:

```xml
<application android:supportsRtl="true" ...>
```

Without it Android ignores the layout direction and the Arabic wall draws left to right.

### Release builds

A non-debuggable app must use an `https` base URL; anything else reports `INTERNAL` and the wall does not open. Offer clicks open only when the click URL is on the wall's own host.

## What the SDK sends

Every request goes to `/sdk/v1` on the wall host and carries what the wall needs to run the same checks it runs on the iframe:

| Field | What |
|---|---|
| User-Agent | The device's own WebView User-Agent plus ` PlayndgainSDK/<version>`, with any character outside printable ASCII removed |
| `sdk`, `sdk_v`, `model` | Platform, SDK version, `Build.MODEL` |
| `dh` | `Build.MODEL`, `PRODUCT`, `DEVICE`, `HARDWARE`, `FINGERPRINT`. Used only by the emulator check |
| `ds` | A small device-signal token in the format the wall's identity checks already read |

Nothing persistent, no advertising ID, no install identifier. `setAdvertisingIdEnabled` and `setChildDirected` are stored and nothing reads them yet.

## Sample app

`sample/` signs on the device with a secret typed into a field. That is a test shortcut and must never ship: a real app asks its own server for the signature.
