# Landing Rebuild — product-led, klinklabs-principled

> **Status (2026-09-01, built):** implemented as approved — amber accent, new geometric mark,
> Example-tagged playbooks, shared pages re-skinned through compatibility tokens, local
> `DemoActivitySeeder` behind the dashboard captures, theme-switcher demo dropped, H1 "Turn playtime
> into payouts." Partner strip is 12 marks, not 16: Offery, Skylup, MyChips and Admantum have no
> usable logo file. See the closing report in the session and `design.md` v3.
> Follow-up the same day: the panels' auth screens were restyled to this system (design.md §10).
> Refinement pass (2026-09-01, later): monthly-payout wording everywhere, honest metric tiles (no
> illustrative figures; "Worldwide" from the catalogue, capability tiles otherwise), the anonymity
> rule in AGENTS.md applied (no partner names or marks; inventory shown by category), four
> capabilities, compact terms, skeleton-framed shots, the motion system (tokens in
> site-tokens.css, reveals gated by html.js + reduced motion), accessible mobile menu.
>
> **For agentic workers:** this is a **ground-up rebuild of the marketing page**, not a delta. The
> old editorial landing (navy page, serif display, hairline grids, tiny wall mocks, ledger table) is
> removed, not restyled. Only routes, data and functionality survive. Wait for the owner's approval
> of the decisions at the end before writing code.

**Reference:** https://klinklabs.com/ (captured 2026-09-01: Framer site, body `#0B0B18`, light
sections `#EEEEF3`, violet `#664DFF`, Inter, radii 40/20/14 px, pill buttons, phone hero mock with
floating stat pills, three dark metric cards, grey logo strip, split "vision" section with one big
product composition card, two large solution cards, three case cards, full-width gradient CTA card,
light footer). We share its **principles**, not its brand — Klink is one of our demand partners.

**Keep (functionality/data):** `LandingController` (`stats` with the pre-launch fallback, `terms`,
`snippet`), `/newsletter` POST, `/contact` (+ topics), `/docs`, legal/about/advertise/status pages,
`/dashboard/register|login` links, social links from config, `Identity` name/url, the response
cache, "renders complete with JS off", accessibility 100, the Lighthouse budget.

---

## 1. Page outline (desktop, top → bottom)

| # | Section | Surface | Purpose |
|---|---|---|---|
| 0 | Header | ink glass, sticky | logo · Publishers · Advertisers · Network · Docs · Terms · Sign in · **Start earning** |
| 1 | Hero | ink + amber glow | H1 + lead + 2 CTAs; **real wall screenshot in a phone**, floating stat pills |
| 2 | Metric cards | ink cards over the hero edge | 4 large cards from `stats` (offers, countries, partners, delivery) |
| 3 | Partner strip | paper | "Demand from the networks you already know" — 17 real logos, monochrome |
| 4 | Product | paper | split: headline + 3 proofs / one big ink card with the **dashboard screenshot** + chips |
| 5 | Solutions | paper | two r-32 cards: **For publishers** (ink gradient) · **For advertisers** (white) |
| 6 | How it works | ink | 3 steps + **network/integration diagram** (app ⇄ PLAYNDGAIN ⇄ logo orbit) + 3-line signature card |
| 7 | Features bento | ink | 2 large + 4 small cards with mini visuals; terms row (70% · NET-30 · $20) |
| 8 | Results | paper | 3 case cards (category pill · headline · image) — framing is an owner decision |
| 9 | Closing CTA | paper section, ink r-32 card | big headline, 2 CTAs, support address, SVG "world of offers" illustration |
| 10 | Footer | paper | logo + tagline · Publishers · Advertisers · Developers · Company · newsletter · socials · legal |

Rhythm: **dark → dark → light → light → light → dark → dark → light → light(dark card) → light.**

## 2. Typography

- **One family, everywhere on the marketing site: Sora (variable 100–800, geometric).**
  Self-hosted from `@fontsource-variable/sora`: `latin` **33.7 KB**, `latin-ext` 15.5 KB by
  unicode-range. No italic exists and none is needed — emphasis is weight and colour.
  (Measured alternates: Plus Jakarta Sans 27.3 KB, Outfit 32.3 KB, Space Grotesk 22.3 KB.)
  Today's ship is 145 KB across three files; this is one preload of 34 KB.
- Scale (px / line-height / tracking / weight):
  `display-1` 72 / 1.02 / −0.03em / 700 · `display-2` 52 / 1.05 / −0.025em / 700 ·
  `h3` 28 / 1.15 / −0.015em / 600 · `metric` 64 / 1 / −0.03em / 700 · `lead` 20 / 1.5 / 400 ·
  `body` 16 / 1.6 / 400 · `small` 14 / 1.5 / 500 · `label` 12 / 1 / +0.08em uppercase / 600.
  Phones: display-1 40, display-2 32, metric 44.
- Buttons: pill, 15 px / 600. Numbers: `tabular-nums` where the face provides it.
- Code (the 3-line signature card, docs): `ui-monospace` system stack, no font file.
- Newsreader and Manrope stay only where the product still uses them (wall, panels) — the site's
  preloads switch to Sora.

## 3. Colour system (`resources/css/site-tokens.css`, semantic names)

**Recommended — "Ink & Amber"** (keeps a thread to the current gold, unmistakably not the editorial look, and not the reference's violet):

| Token | Value | Use |
|---|---|---|
| `ink-950` | `#0A0B14` | dark sections, page under the hero |
| `ink-900` | `#11131F` | cards on ink |
| `ink-800` | `#1A1D2E` | raised tiles, inputs on ink |
| `ink-700` | `#262A40` | borders on ink (1px, 60% alpha) |
| `paper-50` | `#F5F5FA` | light sections |
| `paper-0` | `#FFFFFF` | cards on paper |
| `paper-100` | `#ECECF4` | muted tiles, logo strip chips |
| `text-on-ink` | `#F4F5FA` / `#A6ABBE` | primary / secondary on dark |
| `text-on-paper` | `#0C0D1A` / `#5D6178` | primary / secondary on light |
| `amber-400` | `#FFB020` | primary fill, glow source |
| `amber-500` | `#F28C1A` | gradient end, hover |
| `amber-700` | `#B45309` | small accent text on paper (≥ 4.5:1) |
| `on-amber` | `#1A1000` | text on amber fills |
| `night-600` | `#2A2170` | depth tint inside hero/CTA gradients only — never an action colour |
| `gain` / `loss` | `#16B364` / `#E5484D` | credited / reversed money, unchanged semantics |

Gradients: `--g-hero: radial(60% 50% at 50% 110%, amber-400/28% → transparent), radial(40% 40% at 90% −10%, night-600/45% → transparent) on ink-950`;
`--g-accent: linear(135deg, amber-400, amber-500)`; `--g-card-ink: linear(180deg, ink-900, #1A1230)`.
Shape: `r-32` hero/solution/CTA cards · `r-20` metric/case/feature cards · `r-14` chips, inputs, frames · pills for buttons.
Depth: paper shadow `0 1px 2px rgb(12 13 26/.06), 0 16px 40px −16px rgb(12 13 26/.18)`; accent glow `0 0 0 1px rgb(255 176 32/.25), 0 24px 64px −24px rgb(255 176 32/.45)`; ink cards use a 1px `ink-700` border plus an inner top highlight, no drop shadow.
Motion: hover lift (−2 px + shadow, 160 ms), one breathing hero glow (6 s) — all behind `prefers-reduced-motion: no-preference`; nothing on scroll.

One-line swaps if the owner prefers: **Emerald** (`#34E27A → #12B76A`) or **Violet** (`#7C6CFF → #5B4BE0`; closest to the reference, and therefore the one I would not pick).

## 4. Deleted (current sections and their files)

Sections: old hero · "plate" photograph band · bordered 4-up network strip · bordered 6-up counters ·
premise + **conversion-ledger trace table** · bordered 6-grid platform features · wall section with
the **tiny wall mocks and theme switcher** · integrate (code panel) · terms `<dl>` · photo CTA band ·
current header and footer.

Files removed: `resources/views/partials/landing/{hero,plate,network,counters,premise,platform,wall,wall-card,integrate,terms,cta}.blade.php`,
`app/Support/LandingWall.php`, `public/img/landing/{plate,plate-sm,plate-md,cta,cta-sm,cta-md}.webp`,
the `.landing-wall*` / `.btn` / `.code-panel` rules and the `wall-ui.css` import in `app.css`,
the Newsreader/Manrope preloads in `layouts/site.blade.php`. `LandingPageTest` is rewritten (not
patched); `ReducedMotionTest` is re-pointed at the new gated animations.

## 5. Created

- `resources/css/site-tokens.css`, `site-fonts.css`, and a rewritten `app.css` (marketing only —
  the wall and panels keep `tokens.css`/`fonts.css` untouched).
- `resources/views/partials/landing/{hero,metrics,partners,product,solutions,how,features,results,cta}.blade.php`;
  rewritten `site-header`, `site-footer`, `layouts/site` (meta theme-color, preloads, og image).
- `app/Support/LandingContent.php` — the copy, steps, feature and result cards as data, so the
  page has one place for words.
- **Real product screenshots**: `scripts/capture-landing.mjs` (Puppeteer against the local demo)
  → `public/img/landing/{wall-dark,wall-light,dashboard,postbacks}@{1x,2x}.webp`; a local-only
  `DemoActivitySeeder` gives the demo publisher 30 days of clicks/conversions/ledger rows so the
  dashboard is not a wall of zeros (today: 0 clicks, 0 conversions).
- New geometric **brand mark** (rounded amber tile + bold P) in `components/brand-mark.blade.php`,
  `scripts/make-favicons.php` updated, `public/img/og.png` regenerated — used by the site, the wall
  footer and the panels, so the brand is one brand.
- Network/integration diagram and the CTA illustration as inline SVG built from real data
  (network logos on file, the country list) — no stock art.
- `design.md` rewritten (v3 rules), `docs/09-landing-site.md` §1–§7 rewritten, tests:
  `LandingPageTest` (new content, still: no-JS render, alt on every image, figures final, newsletter
  form, no hidden-start), `ReducedMotionTest` (new animation names), `LandingStatsTest` unchanged.

## 6. Desktop wireframe (1440, 1280 container)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ HEADER  [◆ PLAYNDGAIN]   Publishers  Advertisers  Network  Docs  Terms        │
│                                              Sign in   (● Start earning →)   │
├──────────────────────────────────────────────────────────────────────────────┤
│ 1 HERO · ink · amber glow from the bottom, indigo depth top-right            │
│  ┌── 6 cols ───────────────────────┐  ┌── 6 cols ────────────────────────┐   │
│  │ (pill) 17 networks · one iframe │  │            ╭──────────╮ [+640 ✓] │   │
│  │ Turn playtime                   │  │ [94 ms ↩]  │  PHONE   │ credited │   │
│  │ into payouts.            72/700 │  │  postback  │ real wall│          │   │
│  │ Drop the PLAYNDGAIN wall into   │  │            │  (2x)    │[DE·Android│   │
│  │ any app or game. Players finish │  │            │          │ 312 offers]│  │
│  │ offers; you get paid per action.│  │            ╰──────────╯          │   │
│  │ (● Start earning) (Talk to us)  │  └──────────────────────────────────┘   │
│  │ ✓ Live in a day  ✓ NET-30  ✓ $20│                                         │
│  └─────────────────────────────────┘                                         │
│ 2 METRICS · four ink-900 r-20 cards overlapping the hero's bottom edge        │
│  [1,284+  Live offers] [134  Countries] [12  Demand partners] [99.98%  Delivered]│
├──────────────────────────────────────────────────────────────────────────────┤
│ 3 PARTNERS · paper · "Demand from the networks you already know"             │
│   AdGate · Ayet · Adscend · Klink · Monlix · Notik · OGAds · Torox · Offery … │
├──────────────────────────────────────────────────────────────────────────────┤
│ 4 PRODUCT · paper · 5/7 split                                                │
│  One wall. Every network.        ┌ ink r-32 card ───────────────────────┐   │
│  Your brand.              52/700 │  ┌ browser frame ─────────────────┐  │   │
│  ● Per-app colours, radius,      │  │  publisher dashboard (real)     │  │   │
│    currency                      │  │                                 │  │   │
│  ● Geo · device · OS filtered    │  └─────────────────────────────────┘  │   │
│    per render                    │   [Midnight ▮][Daylight ▯]  [$0.091 ✓]│   │
│  ● One postback format, signed   └──────────────────────────────────────┘   │
├──────────────────────────────────────────────────────────────────────────────┤
│ 5 SOLUTIONS · paper · two r-32 cards                                         │
│  ┌ ink-gradient ───────────────────┐ ┌ white ─────────────────────────────┐ │
│  │ ◉ For publishers                │ │ ◉ For advertisers                  │ │
│  │ Monetise every player.  28/600  │ │ Buy verified actions.              │ │
│  │ 2 lines · (Start earning →)     │ │ 2 lines · (Talk to us →)           │ │
│  │   ╭ phone: wall light theme ╮   │ │  ┌ postback report screenshot ┐    │ │
│  │   │ bleeds past the card     │   │ │  │ bleeds past the card       │    │ │
│  └───┴──────────────────────────┴───┘ └──┴────────────────────────────┴────┘ │
├──────────────────────────────────────────────────────────────────────────────┤
│ 6 HOW IT WORKS · ink                                                         │
│  Live in an afternoon.                                              52/700  │
│  [01 Embed one iframe] [02 Players complete offers] [03 A signed postback pays]│
│  ┌ diagram card r-32 ─────────────────────────────────────────────────────┐  │
│  │ [Your app] ══▶ [◆ PLAYNDGAIN] ══▶ ( orbit of 17 network logos )          │  │
│  │ [Your app] ◀══ signed postback ◀══ ledger row +$0.091                    │  │
│  │ ┌ 3-line signature check (server-highlighted, real macro names) ┐       │  │
│  └─┴───────────────────────────────────────────────────────────────┴───────┘  │
├──────────────────────────────────────────────────────────────────────────────┤
│ 7 FEATURES · ink · bento 2 large + 4 small, r-20                             │
│  ┌ Fraud engine that explains itself ┐ ┌ Append-only ledger ─────────────┐   │
│  │ visual: verdict chips              │ │ visual: two rows, one negative   │   │
│  └────────────────────────────────────┘ └──────────────────────────────────┘  │
│  [Signed callbacks] [Per-app theming] [Geo/device filtering] [Chargebacks shown]│
│  TERMS  70% rev share · NET-30 · $20 minimum · 6 dp — stated once             │
├──────────────────────────────────────────────────────────────────────────────┤
│ 8 RESULTS · paper · "What a first month looks like" · three white r-20 cards  │
│  [Casual game] [Fintech app] [Survey site]  pill · headline · outcome · image │
├──────────────────────────────────────────────────────────────────────────────┤
│ 9 CTA · paper section · one ink r-32 card with amber glow + dotted-world SVG  │
│  Start earning from the players you already have.                    56/700 │
│  (● Start earning) (Read the docs)     support@playndgain.com               │
├──────────────────────────────────────────────────────────────────────────────┤
│ FOOTER · paper                                                               │
│  ◆ PLAYNDGAIN  tagline   | Publishers | Advertisers | Developers | Company    │
│  newsletter [you@company.com][Subscribe]   socials (config)   legal · ©      │
└──────────────────────────────────────────────────────────────────────────────┘
```

## Scope and blast radius

- **In:** `/` and the shared site chrome (header, footer, layout, tokens, fonts, brand mark). Because
  the chrome and tokens are shared, `docs/*`, `/contact`, legal/about/advertise/status get a
  **mechanical re-skin** (new tokens, new type, same layouts) so they are not old-navy pages under a
  new header. No layout redesign for them in this pass.
- **Out:** the offerwall (per-app themed product surface, its `--wall-*` contract) and the Filament
  panels keep their current design. The new brand mark appears on them (same component), nothing else.
- Budget stays enforced: CSS ≤ 30 KB gz (expected ≈ 10 KB once `wall-ui.css` leaves the site
  bundle), JS ≤ 50 KB gz (Alpine for the mobile nav only), fonts ≤ 2 files, a11y 100, LCP ≤ 2.5 s —
  the hero phone image is the LCP element: ≤ 90 KB on phones, ≤ 180 KB desktop, preloaded,
  `fetchpriority="high"`, explicit dimensions, CLS 0.

## Decisions needed before code

1. **Accent:** Amber (recommended) · Emerald · Violet.
2. **Brand mark:** new geometric mark (recommended) · keep the serif P.
3. **Results cards:** illustrative playbooks with an "Example" pill (recommended) · omit until real
   case studies exist · you supply real numbers.
4. **Shared pages:** mechanical re-skin in this pass (recommended) · landing only.
5. **Screenshots:** local-only demo-activity seeder so the dashboard shows data (recommended).
6. **Theme switcher demo:** drop it; theming is shown by two real wall screenshots (recommended).
7. **H1:** "Turn playtime into payouts." (recommended) · "Your players play. You get paid." ·
   "Rewarded offers. Real payouts."
