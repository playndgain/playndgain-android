# Testing the Android SDK

Run on the Mac. Needs the Android SDK at `~/Library/Android/sdk`, an arm64 AVD (`pixel8` here), and JDK 17 or newer.

## 1. Unit tests and builds

```
./gradlew :sdk:testDebugUnitTest :sdk:lintDebug :sdk:assembleRelease :sample:assembleDebug
```

Expected: 44 tests, 0 failures, lint 0 errors. `StoreLinkTest` pins which URLs are Play listings (web, `market://`, `intent://`); `OfferResolutionTest` drives every navigation the click can take (store, app link, landing, a wait that runs out, failure) without a WebView. `sdk/build/outputs/aar/sdk-release.aar` and `sample/build/outputs/apk/debug/sample-debug.apk`.

## 2. Serve the wall

From the wall repo, with a database that has an active app:

```
cd public
DB_DATABASE=<db> PLAYNDGAIN_DEV_COUNTRY=US APP_URL=http://10.0.2.2:8765 \
  php -S 0.0.0.0:8765 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

`php artisan serve` does not pass environment overrides through to its worker, so use the built-in server directly.

## 3. Install and launch the sample

```
~/Library/Android/sdk/emulator/emulator -avd pixel8 &
adb wait-for-device
adb install -r sample/build/outputs/apk/debug/sample-debug.apk
adb shell am start -n com.playndgain.sample/.MainActivity \
  --es base http://10.0.2.2:8765 --es userid USER_1
```

The `base`, `appid`, `userid` and `lang` extras pre-fill the form. Tap **Show**.

## 4. Fraud parity first (emulator rule ON, test mode OFF)

A stock AVD must be refused exactly as an iframe in a WebView would be.

- **Show** lands on "Offers unavailable" (`blocked_unavailable`). Screenshot: `docs/screens/01-parity-sdk-blocked.png`.
- The server's `fraud_events` row for that user has `rule_key = emulator_device`. Its context carries the WebView UA (`... sdk_gphone64_arm64 Build/...; wv ... PlayndgainSDK/1.0.0`) and the `dh` hint.
- After an offer click from the SDK, the `offer_clicks` row stores `device_hint`. With the rule on, the click is blocked as `emulator_device`: since 1.1 the click runs in the SDK's own WebView, whose UA carries the build string, and `dh` is there as well. The wall's refusal page then rises in the in-app sheet.

Known, and older than the SDK: the wall URL opened straight in the emulator's **Chrome** is not refused, because Chrome's reduced UA has no build string. Inside an app the iframe runs in a WebView, whose UA has one, and is refused. Screenshot: `docs/screens/02-parity-iframe-chrome.png`.

## 5. Functional checklist (emulator rule OFF, test mode ON)

Turn `emulator_device` off in the local `fraud_rules` table and test mode on for the app, then:

| Check | Expected | Screenshot |
|---|---|---|
| List loads | Header, tabs, search, category chips, sort, featured rail, cards | `03-offers.png` |
| Detail | Sheet with steps, notes, who it is for, Important box, Start | `04-detail.png` |
| Start | A loading card, then the click's outcome inside the app: a Play listing opens the Play Store app; a web page rises as a sheet with Close. One click row is written with `sdk_platform=android`, `sub1` | `05-click-browser.png` |
| Back in the sheet | Goes back inside the page, then closes the sheet | |
| Return | Closing the sheet, or coming back from the Play Store: `onRewardCheck` once in the sample log, list reloads | |
| Unreachable click | Airplane mode on after the list loads, then Start: the list stays and a sheet says "Offer unavailable", `onError(NETWORK)`, no `onRewardCheck` | |
| History | Rows with status as a word, credited amount in the primary colour, partner rows say "Partner wall" | `06-history.png` |
| Arabic | `--es lang ar`: mirrored layout, chevrons point left, system Arabic face. Needs `supportsRtl="true"` in the host manifest (the sample sets it) | `07-arabic-rtl.png` |
| Offline | Airplane mode: "You are offline", Try again recovers | `08-offline.png`, `09-offline-retry.png` |
| Consent | Consent country: Before you start, Accept / Decline of equal weight; Accept loads offers | `10-consent.png`, `11-consent-accepted.png` |
| Close | `onClosed` once | |

Turn `emulator_device` back on when you are done.
