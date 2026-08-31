---
title: Offerwall iframe
description: The primary integration. One iframe, two required parameters.
---

The wall is hosted by us and embedded by you. There is no SDK, no npm package and no script tag.

## The embed

```html
<iframe
  src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID"
  width="100%"
  height="800"
  frameborder="0"
  allow="clipboard-write"
  title="Rewards"></iframe>
```

Two parameters are required: `appid` and `userid`. Everything else is optional and listed in [URL parameters](/docs/url-parameters).

## Sizing

The wall is mobile-first: one column, two at 640 px, three at 1024 px. It scrolls internally, so a fixed height is fine and is what most publishers use.

- **Minimum usable width:** 320 px.
- **Recommended height:** 600–900 px, or `100%` of a container you control.

The wall does not post its height to the parent frame. If you want to size the frame to the content, give it a viewport-relative height instead:

```html
<iframe src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID"
        style="width:100%;height:calc(100vh - 120px);border:0"></iframe>
```

## Permissions

`allow="clipboard-write"` lets an offer's instructions copy a promo code. Leave it out and the copy buttons inside offer instructions silently do nothing.

If you use `sandbox`, you must include at least:

```txt
sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-top-navigation-by-user-activation"
```

`allow-popups` and `allow-top-navigation-by-user-activation` matter: offer clicks navigate to a third-party tracking URL. Without them the click goes nowhere and the user sees a blank frame.

## In a mobile app

Use a system web view — `WKWebView` on iOS, `WebView` on Android — with JavaScript and third-party cookies enabled. Point it at the same URL.

Do not open the wall in an in-app browser that blocks redirects to app stores. Most CPI offers end in a store link, and a web view that cannot follow it converts at zero.

## What not to do

- **Do not put the secret key in the URL.** The iframe URL is client-side. If your app requires signed wall URLs, the signature is computed **on your server** — see [Signed URLs](/docs/signed-urls).
- **Do not reuse one `userid` across users.** Everyone gets the same rewards and your fraud score collapses.
- **Do not cache the iframe HTML.** The wall sends `Cache-Control: private, no-store`; honour it. Offers change and completed offers disappear per user.
- **Do not open the wall in a new window from an ad.** That is incentivised pop traffic and it is [prohibited](/docs/prohibited-traffic).

## Consent

When the resolved country requires consent — the EU-27, the UK and the EEA — the wall renders a consent screen before any offer. Accepting stores consent per `(app, end user)`, so it survives an iframe reload. Declining shows an explanation and **no offers**.

You do not need to build anything for this. You do need to know that a European user who declines will see an empty wall, and that this is correct behaviour rather than a bug.

We are **not** a registered IAB TCF vendor and this page does not claim TCF compliance.

## Next

[URL parameters](/docs/url-parameters) types every parameter the wall accepts.
