---
title: Theming
description: Four colours, one radius, one flag. Set per app, previewed live, contrast checked.
---

The wall wears your brand. Colours, corner radius and light or dark are set per app in **Apps → Theme**, with a live preview beside the form. Your players never see a mark that is not yours, apart from one line of attribution in the footer.

## The theme object

Stored per app and injected as CSS custom properties on the wall's `:root`. There are no per-app stylesheets and no per-app builds. These are the defaults:

```json
{
  "primary": "#FFB020",
  "background": "#0A0B14",
  "card": "#11131F",
  "text": "#F4F5FA",
  "radius": "14px",
  "dark": true
}
```

| Key | Type | Applies to |
|---|---|---|
| `primary` | hex colour | The balance pill, the letter tile on an offer, the start button and its glow, the active tab, focus rings. Filled elements take a two-stop gradient of it |
| `background` | hex colour | The page behind the rows |
| `card` | hex colour | Each offer row |
| `text` | hex colour | Body and headings. Every rule and every muted shade is mixed from `text` and `background`, so four colours produce a coherent wall |
| `radius` | CSS length | Cards, tiles and inputs. Controls inside a card take six tenths of it and sheets nearly twice, so the scale stays yours. Chips, tabs, buttons and the balance are always pills |
| `dark` | bool | Sets the wall's `color-scheme`, so native controls and scrollbars match |

Omit any key and it falls back to the default above. A partial theme is valid.

## Four presets

The form starts from one of four palettes. Each is complete and contrast checked. Change anything afterwards.

| Preset | `primary` | `background` | `card` | `text` | `dark` |
|---|---|---|---|---|---|
| Ink & Amber | `#FFB020` | `#0A0B14` | `#11131F` | `#F4F5FA` | `true` |
| Paper | `#F28C1A` | `#F5F5FA` | `#FFFFFF` | `#0C0D1A` | `false` |
| Forest | `#6FD3A3` | `#07231B` | `#0C3227` | `#E6F2EC` | `true` |
| Plum | `#C9A6F0` | `#1A1026` | `#241634` | `#F2ECF8` | `true` |

## Contrast is checked, not assumed

The form tests `text` against `background` and against `card`, and warns you when the pair falls below 4.5:1. It will not quietly accept a palette that makes your own reward figures unreadable.

One value you cannot set is derived for you: whichever of black or white sits further from your `primary`, so anything filled with your accent carries legible text no matter what accent you pick.

A reward never depends on colour alone either. It carries your currency name as text, so a player with low colour vision reads "350 Coins" rather than a coloured number.

## Overriding the scheme per load

`?theme=light` or `?theme=dark` overrides the scheme for one page load. Use it when your own product has a theme toggle and you want the wall to follow it:

```html
<iframe src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID&theme=light"></iframe>
```

`theme=light` paints the Paper surfaces. `theme=dark` paints the Ink surfaces. Your `primary` and radius stay. The parameter never accepts a palette.

## What you cannot theme

- **Offer order.** Featured first, then payout descending. Not reorderable
- **Offer icons.** They come from the demand partner and are proxied through us, never hotlinked. An offer without one gets a letter tile in your `primary`
- **Layout.** One column on a phone, two at 640px, never three. A third column clips the instruction after four words
- **Copy.** Offer names and instructions are the advertiser's, verbatim

The wall is served from our origin, so your stylesheet cannot reach into the frame. If your brand needs something the theme object cannot express, that is a conversation with support rather than a CSS override.
