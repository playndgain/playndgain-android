# PLAYNDGAIN — Design Rules

**Version 3 — "Ink & Amber", 2026-09-01; extended to the wall 2026-09-02.**
These rules govern the marketing site: the landing page, the docs, the legal
pages and the contact form. The Filament panels and the offerwall are product
surfaces with their own contract (docs/03 §5, docs/07, docs/08); both now wear
this system on top of it — the panels since 2026-09-01, the wall since
2026-09-02 (§10).

The direction, in one paragraph: a product-led page in the manner of the
modern performance-marketing networks — a bold geometric sans, an immersive
gradient hero, real product captures that dominate the composition, sections
that alternate between ink and paper, large rounded cards, capabilities as
cards, a
partner strip, two solution cards, an integration diagram, worked playbooks
and one big closing card. Short copy. No serif, no hairline grids, no
editorial ledger, no mock of the wall drawn in HTML.

## 1. Two token files, and why

- `resources/css/site-tokens.css` — the marketing site. Imported by `app.css`
  only. Every colour, radius, shadow and duration on the site is a name from
  this file; Tailwind's default palette is switched off so a stray
  `text-gray-500` cannot compile.
- `resources/css/tokens.css` — imported by the wall and the panels for the
  names their older utilities still read; both override the faces and the
  motion tokens to this system's (`wall.css`, `filament/theme.css`).
- `resources/css/brand-mark.css` — the one file all three bundles share.

The site file also carries a block of compatibility names (`mist`, `line`,
`gold`, `rounded-control`) mapped onto the new ramp, so the docs, legal and
contact templates re-skinned without a rewrite. Retire that block when their
classes migrate; do not add to it.

## 2. Colour

Two surfaces, each with its own text ramp, and one accent.

| Role | Token | Value |
|---|---|---|
| Ink section / page | `ink-950` | `#0A0B14` |
| Card on ink | `ink-900` | `#11131F` |
| Tile, input on ink | `ink-800` | `#1A1D2E` |
| Border on ink | `ink-700` · `ink-600` | `#262A40` · `#343954` |
| Paper section | `paper-50` | `#F5F5FA` |
| Card on paper | `paper-0` | `#FFFFFF` |
| Tile on paper | `paper-100` | `#ECECF4` |
| Border on paper | `paper-200` · `paper-300` | `#DFDFEA` · `#C9CAD9` |
| Text on ink | `fog-50` · `fog-300` · `fog-400` · `fog-500` | `#F4F5FA` · `#C3C7D6` · `#A6ABBE` · `#7E839A` |
| Text on paper | `coal-900` · `coal-700` · `coal-600` · `coal-400` | `#0C0D1A` · `#3A3E52` · `#5D6178` · `#8A8FA8` |
| Accent | `amber-300` → `amber-400` → `amber-500` | `#FFC554` → `#FFB020` → `#F28C1A` |
| Accent text on paper | `amber-700` | `#B45309` (4.6:1 on white) |
| On an amber fill | `on-amber` | `#1A1000` |
| Depth in gradients | `night-600` | `#2A2170` — never an action colour |
| Credited / reversed | `gain-500` · `loss-500` | `#16B364` · `#E5484D` (`-300` variants on ink) |

Rules:

- **Sections alternate.** Ink, ink, paper, paper, paper, ink, ink, paper,
  paper, paper (the closing card is ink on paper). A section says which it is
  with `.on-ink` / `.on-paper`, and everything inside reads the matching ramp.
- **Amber is a fill, a glow and a gradient.** Buttons, the hero's ground, the
  mark, icon tiles, small kickers. On paper the only amber *text* is
  `amber-700`; `fog-500` and `coal-400` are decorative, never body copy.
- **Gradients are two amber stops at 135°, or a radial glow.** Nothing else
  is a gradient; the night tint exists only to give the hero and the closing
  card depth top-right.
- **Money keeps its colours** wherever a figure is money: credited is gain,
  reversed is loss, and neither is used for anything decorative.

## 3. Type

One family: **Sora**, variable 100–800, self-hosted (`site-fonts.css`, 33.7 KB
Latin on the critical path, latin-ext by unicode-range). There is no italic and
the site uses none — emphasis is weight and colour.

| Role | Size / line / tracking / weight |
|---|---|
| `display-1` | clamp(42–72px) / 1.02 / −0.03em / 700 |
| `display-2` | clamp(32–52px) / 1.06 / −0.025em / 700 |
| `display-3` | clamp(24–32px) / 1.12 / −0.02em / 700 |
| `h3` | clamp(20–24px) / 1.2 / −0.015em / 600 |
| `metric` | clamp(40–64px) / 1 / −0.03em / 700, tabular |
| `lead` | clamp(17–20px) / 1.55 / 400 |
| body | 16 / 1.6 / 400 · `small` 14–15 / 500 |
| `label` · `eyebrow` | 12 / uppercase / +0.08–0.1em / 600–700 (eyebrow is amber) |

Buttons are 15px / 600. Figures use `tabular-nums` where the face provides
them. Code is the system mono stack — no font file for it.

## 4. Shape and depth

- Radii: **32** for the big containers (hero card, solution cards, the
  closing card, the diagram card), **20** for cards (metrics, features,
  playbooks, steps), **14** for chips, frames and inputs, **pills** for
  buttons and kickers. Nothing on the site is square.
- Depth on paper is a soft layered shadow (`shadow-paper`, `shadow-paper-lg`
  on hover). Depth on ink is a 1px `ink-700` border plus a 1px top highlight
  — ink cards never cast shadows on ink.
- The accent glows: `shadow-glow` under primary buttons, a radial orb behind
  the hero phone and inside the closing card.
- Device frames are CSS (`.phone`, `.browser`); the captures inside them are
  real screenshots (§6).

## 5. Motion

One system, all `transform` and `opacity`, every value a token in
`site-tokens.css`: `--motion-fast` 150 ms (hover, press, focus, colour),
`--motion-base` 260 ms (menus, chips, image fades, elevation),
`--motion-enter` 560 ms (entrances), `--motion-exit` 200 ms (anything
leaving), `--motion-stagger` 70 ms (siblings in a group), `--motion-ambient`
14 s (the glows), `--ease-out` `cubic-bezier(.22,1,.36,1)` for arrivals and
`--ease-in` for exits. No animation library: `site.js` toggles classes and
writes stagger indices; the stylesheet does the moving.

Three kinds. **Entrances**: the hero enters on load, once per tab session
(eyebrow → claim → lead → actions → proofs; the phone with a small lift and
scale). Every later block enters once as it comes into
view — 20 px and a fade, groups staggered — and the small sequences inside a
card (verdict chips, ledger rows, the diagram's nodes and labels and one lap
of its dot) follow their card.
**Interaction**: 2–4 px lifts with border and shadow on pointers that can
hover, a pressed state everywhere, colour and shadow transitions on touch.
**Ambient**: the hero and closing glows drift over 14 s; a frame shimmers
until its image has decoded.

Two gates. Nothing is ever hidden unless `html.js` is present, so the page
without JavaScript is fully visible from the first byte; and under
`prefers-reduced-motion: reduce` no entrance, counter, lift or loop runs —
the page is what the server sent. A real figure may count up for 600 ms;
nothing counts up from a number that is not real.

## 6. Imagery

- **Product captures, not illustrations.** `scripts/capture-landing.mjs`
  screenshots the real wall (dark and light themes, phone viewport) and the
  real publisher dashboard and postback report from the local demo account
  (`DemoWallSeeder` + `DemoActivitySeeder`), and writes WebP at 1× and 2×.
  Each wall URL names its theme with `?theme=`, because the app behind
  `APP_ID` has a theme of its own and a file called `wall-dark` that renders
  paper is simply mislabelled. The only edits are removing the local
  environment's pinned test-offer row and scrolling the dashboard to its
  balances — the panel's own greeting and empty daily total are true and are
  a poor argument, and scrolling a sticky-sidebar page is not retouching.
- **Offer art is the app's own.** `database/fixtures/offer-art` carries one
  committed file per app the demo catalogue names, each verified against that
  app's bundle id and seller by `demo:offer-art` and stripped of every
  metadata segment on the way in. Offers with no app identity carry no art and
  render the letter tile, so the fallback stays visible on the demo wall.
- **Partner marks as silhouettes.** The network logos in `public/img/networks`
  arrive black, white or in colour; `.logo-mono` (`grayscale contrast(0)
  brightness`) sends every opaque pixel to one grey so the strip and the
  orbit read as one quiet set.
- **Diagrams from data.** The integration orbit places the partner marks by
  arithmetic; the closing card's globe is a few hundred SVG circles projected
  from latitude and longitude. No image files.
- **No stock photography, no generated "atmosphere" plates, no 3D coins.**

## 7. Copy

Short and direct: a claim, a sentence of how, a verb on the button. Figures
on the page are real or absent, and the proof band under the hero has none by
design: it used to count live offers, countries, active partners and callback
delivery, each with a sample floor and a capability to fall back on, and those
counts described how long the install had been running rather than what it
does for a publisher — a partner count on a public surface most of all
(AGENTS.md). It states four capabilities instead, the same four on every
install. Operational figures live on the surfaces that report them: the
dashboard, the statistics page, the postback report. Nothing invents a
partner, country, offer, client or result.

Nothing floats over a capture any more: the pills there carried figures
belonging to offers and conversions that did not exist, and they stood in front
of the catalogue they were annotating. What a capture gets instead is one line
saying whose account is in frame. Every playbook card carries an **Example**
tag until there are real accounts to name. Payouts are described as
**monthly** — earnings clear on the 1st of the month after they are earned —
never as NET-30 or any promise of a fixed number of days. The words live in
`App\Support\LandingContent`, one place.

## 8. Accessibility, non-negotiable

Lighthouse accessibility stays at 100. Contrast pairs are verified, not
assumed: `fog-400` on `ink-950` 9.1:1, `fog-500` on `ink-950` 5.6:1,
`coal-600` on `paper-50` 5.9:1, `amber-700` on white 4.6:1, `on-amber` on
`amber-400` 12:1. One focus ring (amber, 2px, 3px offset) on every surface.
`alt` on every image; decorative overlays `aria-hidden`. Every page renders
completely with JavaScript disabled; the mobile menu's trigger is `x-cloak`
and the `<noscript>` list carries the same links.

## 9. What we are deliberately not doing

Serif display type · a navy page · hairline grid compositions · the
conversion-ledger table as a landing feature · HTML mocks of the wall ·
scroll choreography and count-ups · stock or generated photography ·
fabricated case studies · a violet accent that would make the page read as a
clone of a partner network.

## 10. The panels and the wall

The panels' **auth screens** (login, register, password reset, email
verification, the admin 2FA challenge) follow this system: the `.fi-simple-layout`
scope in `resources/css/filament/theme.css` sets Sora, the ink card at 32px, the
amber pill and 14px inputs, and `filament/auth-brand.blade.php` puts the
landing hero beside the form — claim, three proofs and the real wall — with
admin copy on the admin panel. Sora is fetched only inside that scope.

Past the sign-in, the **panels** follow the same system (2026-09-01): Ink &
Amber on Filament's own variables, Sora as the one face (weight carries the
hierarchy; the serif is gone from the panels), the site's shape scale (pills
for buttons and badges, 12–14px controls, 20px cards, 24px modals), and a
structure of their own so they do not read as a stock admin — no top bar; the
brand, the search and the account live in a sidebar that sits directly on the
ink ground, and the page is a raised sheet with a 28px corner and a hairline.
The active nav item is an amber-tinted pill; group labels are the site's
eyebrows; table headers its labels. All of it is pinned in
`resources/css/filament/theme.css`; `tokens.css` is still imported only because
the wall's live preview (`wall-ui.css`) reads it and must keep looking like the
wall — the preview keeps the wall's faces and colours.

The **wall** follows the system too (2026-09-02), on top of its per-app
theming contract, which is untouched: the six `--wall-*` properties are still
the only inputs and every other colour is mixed from them. What the system
adds is everything a publisher does not choose — Sora as the one face with
weight carrying the hierarchy (the serif is gone); pills for chips, tabs,
buttons and the balance, with the publisher's radius on cards, tiles and
inputs, six-tenths of it inside a card and nearly twice on the sheets; the
primary as a two-stop gradient with one glow under the primary action and a
tint behind the active tab; the site's motion tokens for every transition,
the 20 px rise on entrances (rail, then grid, staggered and capped) and the
rise-and-scale on the sheets; and 2 px lifts on pointers that can hover. The
defaults an app ships with are this system's (`WallContext::DEFAULT_THEME`:
amber on ink, 14 px); `?theme=light` on an app with no background renders
paper. The mark reaches every surface through `components/brand-mark.blade.php`
and `brand-mark.css`, in Sora everywhere now.
