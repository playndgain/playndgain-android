---
title: Embedding the wall
description: One iframe, two required parameters, and the four attributes that decide whether clicks work.
---

The wall is a page we host and you embed. There is no SDK, no package to install and no script tag.

## The embed

```html
<iframe
  src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID"
  width="100%"
  height="800"
  frameborder="0"
  allow="clipboard-write"
  title="Earn coins"></iframe>
```

`appid` and `userid` are required. Everything else is optional and typed in [URL parameters](/docs/url-parameters).

Give the frame a `title` your players would recognise. It is what a screen reader announces when focus enters the wall, and "iframe" is not an answer.

## Sizing

The wall is mobile first: one column, two at 640px, three at 1024px. It scrolls inside itself, so a fixed height is fine and is what most publishers use.

- **Minimum usable width:** 320px
- **Recommended height:** 600px to 900px, or `100%` of a container you control

The wall does not post its height to the parent frame. To size the frame to the viewport instead, give it a viewport-relative height:

```html
<iframe src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID"
        style="width:100%;height:calc(100vh - 120px);border:0"></iframe>
```

## The attributes that matter

`allow="clipboard-write"` lets an offer's instructions copy a promo code. Leave it off and the copy buttons inside offer instructions do nothing, silently.

If you use `sandbox`, it must include at least these four:

```txt
sandbox="allow-scripts allow-same-origin allow-popups allow-top-navigation-by-user-activation"
```

The last two are the ones people miss. An offer click navigates to a third-party tracking URL, and without those permissions the click goes nowhere and your player is looking at a frame that appears broken.

## Inside a mobile app

Use a system web view: `WKWebView` on iOS, `WebView` on Android, with JavaScript and third-party cookies enabled. Same URL.

Do not use an in-app browser that blocks redirects to the app stores. Most install offers end at a store link, and a web view that cannot follow one converts at zero while looking like it works.

## Four things not to do

**Do not put the secret key in the URL.** The wall URL is client-side and readable by anyone. If your app requires signed wall URLs, the signature is computed on your server. See [Signed wall URLs](/docs/signed-urls).

**Do not reuse one `userid` across players.** They all see the same completed offers, they all get each other's rewards, and your fraud score collapses.

**Do not cache the wall HTML.** It is sent with `Cache-Control: private, no-store` because the offer list is per player and completed offers disappear from it. Honour that and do not layer your own cache on top.

**Do not open the wall from an ad or a pop.** That is incentivised pop traffic, and it voids the conversions it produces. See [Traffic rules](/docs/traffic-quality).

## Consent in Europe

When the resolved country is in the EU-27, the UK or the EEA, the wall shows a consent screen before any offer. Accepting stores consent per app and player, so it survives a reload. Declining shows an explanation and no offers.

You do not build anything for this. You do need to know that a European player who declines sees an empty wall, and that this is the wall working rather than failing.

We are not a registered IAB TCF vendor and this page does not claim TCF compliance.

## Next

[URL parameters](/docs/url-parameters) types every parameter the wall accepts.
