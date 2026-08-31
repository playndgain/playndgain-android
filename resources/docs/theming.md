---
title: Theming
description: Colours, radius, dark mode. Set once per app, previewed live.
---

The wall takes its colours from the app, not from the URL. Set them in **Apps → Theme**, where a live preview sits next to the form.

## The theme object

Stored per app and injected as CSS custom properties on the wall's `:root`. There are no per-app stylesheets and no per-app builds.

```json
{
  "primary": "#F5A524",
  "background": "#0B0C0E",
  "card": "#15171B",
  "text": "#E8EAED",
  "radius": "12px",
  "dark": true
}
```

| Key | Type | Applies to |
|---|---|---|
| `primary` | hex colour | Reward pills, focus rings, the active filter chip |
| `background` | hex colour | Page background behind the cards |
| `card` | hex colour | Card surface |
| `text` | hex colour | Body and heading text |
| `radius` | CSS length | Corner radius on cards, pills and chips |
| `dark` | bool | Sets the wall's `color-scheme`, so native controls and scrollbars match |

Any key you omit falls back to the AdVault default. A partial theme is valid.

## Contrast is validated, not assumed

The theme form checks `text` against `background` and against `card`, and **warns you when the pair falls below 4.5:1**. It does not silently accept a palette that makes your own reward amounts unreadable.

The reward pill never relies on colour alone — it always carries your currency name as text, so a user with low colour vision still reads "350 Coins" rather than a coloured blob.

## The theme URL parameter

`?theme=light` or `?theme=dark` overrides the app's `dark` flag for one page load. Use it when your own product has a theme toggle and you want the wall to follow it:

```html
<iframe src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID&theme=dark"></iframe>
```

The colours themselves still come from the app. `theme` picks which of the two the wall renders as, it does not accept a palette.

## What you cannot theme

- **Offer order.** Featured offers first, then payout descending. Publishers cannot reorder in v1.
- **Offer icons.** They come from the network and are proxied through us, never hotlinked.
- **Layout.** One column, two at 640 px, three at 1024 px.
- **Copy.** Offer names and instructions are the advertiser's, verbatim.

If your brand needs a layout the wall does not offer, that is a conversation with support, not a CSS override — the wall is served on our origin and your stylesheet cannot reach into the frame.
