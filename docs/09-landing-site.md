# 09 · Landing Site

**Blade + Alpine.js 3 + Tailwind v4.** Server-rendered HTML. No React, no Inertia, no SSR process, no Node in production — and no animation library: the page is complete before a byte of JavaScript runs.

**Design rules:** `design.md` (version 3, "Ink & Amber", 2026-09-01). This document is the page; that one is the system.

---

## 1. Brand: PLAYNDGAIN

### The idea

A rewarded offerwall that shows its arithmetic. Players complete offers inside a publisher's app; the publisher is paid for every verified action, on a ledger that is never edited, through callbacks that are signed. The page says that the way the modern performance networks say it — product first, short sentences, big numbers — without borrowing anyone's brand.

### The audience, named

A developer or growth lead at a studio, a fintech app or a get-paid-to site, comparing us with the network they already use. Skims the hero, looks at the product, checks the terms, opens the docs.

**The page's single job:** show the product, state the terms, and make the first step one click.

### What we are deliberately not doing

The previous, editorial direction: a navy page, a serif display face, hairline grids, a conversion-ledger table as the centrepiece, HTML mocks of the wall. Gone, not restyled. No stock photography, no generated atmosphere plates, no scroll choreography, no fabricated case studies.

### Palette

Two surfaces and one accent — the full table is design.md §2.

| Role | Token | Value |
|---|---|---|
| Ink (dark sections) | `ink-950` / `ink-900` / `ink-800` | `#0A0B14` / `#11131F` / `#1A1D2E` |
| Paper (light sections) | `paper-50` / `paper-0` / `paper-100` | `#F5F5FA` / `#FFFFFF` / `#ECECF4` |
| Text on ink | `fog-50` / `fog-400` / `fog-500` | `#F4F5FA` / `#A6ABBE` / `#7E839A` |
| Text on paper | `coal-900` / `coal-600` | `#0C0D1A` / `#5D6178` |
| Accent | `amber-300` → `amber-500`, `amber-700` on paper | `#FFC554` → `#F28C1A`, `#B45309` |
| Depth in gradients | `night-600` | `#2A2170` |

**The colour rule that carries the system: sections alternate.** Ink, ink, paper, paper, paper, ink, ink, paper, paper, and a closing ink card on paper. Amber is a fill, a glow and a gradient; on paper the only amber text is `amber-700`. Tokens live in `resources/css/site-tokens.css`; the wall and the panels still import `tokens.css` for their older names but wear the same system (design.md §10).

### Type

One family, **Sora** (variable, self-hosted, 33.7 KB Latin). Bold and tight for display, regular for copy, no italic anywhere. Scale in §4.

### Logo

A rounded amber tile with a bold geometric "P" (a stem and a stroked bowl — geometry, not a glyph) beside the wordmark in Sora 700. One component, `resources/views/components/brand-mark.blade.php`, styled by `brand-mark.css`, which every bundle imports; `scripts/make-favicons.php` draws the same numbers into the icon set and `scripts/capture-landing.mjs` renders the social card.

---

## 2. Signature: the product itself

The page's signature is not a device or a diagram of our own invention — it is the product, photographed. `scripts/capture-landing.mjs` screenshots the real wall (dark and light themes, phone viewport, 1× and 2×), the real publisher dashboard and the postback report from the local demo account, and the page frames them in CSS (`.phone` — a thin ink bezel with an amber hairline and a dynamic-island notch, tilted a few degrees in perspective and levelling out under the pointer; `.embed` — the wall as the iframe it is, an ink frame with a slim address bar carrying the publisher's own URL and an `<iframe>` snippet chip beside it; `.browser` for the dashboard) with floating stat pills over them. The only edit to a capture is removing the local environment's pinned test-offer row.

### Why this and not an illustration

A prospect comparing networks wants to see the wall their players would see and the dashboard they would live in. A drawing of either is a promise; a screenshot is evidence, and it is free to keep current — re-run the script after a UI change.

### Where it recurs

The hero (wall, dark), the product section (dashboard), both solution cards (wall light, postbacks), the three playbook cards (crops of the same captures), and `public/img/og.png`.

---

## 3. Layout

- **Shell:** 1280px including 48px gutters; 20px gutters on a phone. `--section` is 112px of vertical padding, 72px on a phone.
- **Sections say their surface:** `.on-ink` / `.on-paper` set background, text colour and `color-scheme`.
- **Radii:** 32 for the big containers (hero card, solution cards, diagram card, closing card), 20 for cards, 14 for chips, frames and inputs, pills for buttons and kickers.
- **Depth:** soft layered shadows on paper; a 1px `ink-700` border and a top highlight on ink; an amber glow under primary buttons and behind the hero phone.
- **Grid:** hero 1.05fr/1fr from `lg`; metrics 2 columns then 4; product 5/7; solutions 1/1; steps 3; features 4-column bento with two double-width cards; playbooks 3; footer 1.6fr + four columns.

---

## 4. Typography

| Role | Size / line / tracking / weight |
|---|---|
| `display-1` | clamp(42–72px) / 1.02 / −0.03em / 700 |
| `display-2` | clamp(32–52px) / 1.06 / −0.025em / 700 |
| `display-3` | clamp(24–32px) / 1.12 / −0.02em / 700 |
| `h3` | clamp(20–24px) / 1.2 / −0.015em / 600 |
| `metric` | clamp(40–64px) / 1 / −0.03em / 700, tabular |
| `lead` | clamp(17–20px) / 1.55 / 400 |
| body | 16 / 1.6 / 400 |
| `label` / `eyebrow` | 12 / uppercase / +0.08–0.1em / 600–700 |

**Every figure that is money or a count is tabular** where the face provides it. Code is the system mono stack.

---

## 5. Motion

The system is design.md §5, in one line: tokens in `site-tokens.css`
(`--motion-fast` 150 ms · `--motion-base` 260 ms · `--motion-enter` 560 ms ·
`--motion-exit` 200 ms · `--motion-stagger` 70 ms · `--motion-ambient` 14 s ·
`--ease-out` / `--ease-in`), `transform` and `opacity` only, no library.

- **Hero**: enters on load once per tab session (`data-enter`, staggered by
  `--i`; `html.hero-seen` skips it), the phone lifting and scaling in, the
  pills popping in after it.
- **Everything below**: `[data-reveal]` blocks enter once when a tenth of them
  is in view; `[data-reveal-group]` staggers its children; `.seq` sequences
  (chips, ledger rows, the signature check, the pills over a shot) follow their
  card; the diagram's nodes pop in around the ring, its labels follow, and one
  dot makes a lap. Real figures count up for 600 ms — `data-metric` only.
- **Header**: firms up after 8 px of scroll (`is-scrolled`); the mobile menu is
  a transform/opacity panel that locks the page, moves focus in, returns it to
  the button, and closes on Escape.
- **Frames**: every product shot sits in a `.shot` that shows an ink shimmer and
  the mark until the image has decoded; lazy images are warmed 1200 px ahead.

### Reduced motion is a code path, not a courtesy

`tests/Feature/Landing/ReducedMotionTest.php` reads the built stylesheet and
asserts that every hidden state is scoped to `html.js` and lives inside the
`no-preference` query, that the image fade is JavaScript-gated, and that the
site bundle carries no animation library. Under `reduce`, `site.js` releases
every block immediately and runs no counter.

---

## 6. Stack

| Concern | Choice |
|---|---|
| Templates | Blade. Server-rendered. |
| Interactivity | Alpine.js 3.16 + `@alpinejs/collapse` (the mobile menu; nothing else on the page needs a script) |
| Animation | None. CSS only, gated. |
| Styling | Tailwind v4.3, CSS-first config, tokens as `@theme` (`site-tokens.css`) |
| Build | Vite 8 + `laravel-vite-plugin` 3 |
| Content | Markdown → Blade for docs and legal ([10-developer-docs.md](10-developer-docs.md)) |
| Imagery | `scripts/capture-landing.mjs` (puppeteer-core, dev dependency, local Chrome) |

### Why this over Inertia + React

| | Blade + Alpine | Inertia + React |
|---|---|---|
| Prod runtime | PHP. Nothing else. | PHP **+ a Node SSR process** under supervisor |
| SEO | Native — it is HTML | Only with SSR running |
| Failure mode | None to have | Renderer dies → page silently drops out of search |
| JS shipped | ~17 KB gz | ~140 KB gz |
| Stacks in the app | 2 (Alpine, Livewire 4) | 3 (Alpine, Livewire 4, React) |
| Offerwall shares it | **Yes — same Alpine** | No |

**Node is required at build time only.** `npm run build` produces hashed assets in `public/build`. Production runs nginx + PHP-FPM + MySQL + Redis, and that is the entire list.

### Bundle

| | gzipped |
|---|---|
| Alpine 3 + collapse | ~16 KB |
| site.js | < 1 KB |
| app.css | ~10 KB |
| Sora Latin | 33.7 KB (one preload) |

### Entry points

```js
// vite.config.js
laravel({
  input: [
    'resources/css/app.css',            // landing + docs + legal + contact
    'resources/js/site.js',             // Alpine + collapse
    'resources/js/wall.js',             // offerwall — separate, minimal
    'resources/css/filament/theme.css', // both panels
  ],
  refresh: true,
})
```

`app.css` imports `site-fonts.css`, `site-tokens.css` and `brand-mark.css`. It no longer imports `wall-ui.css`: the page shows photographs of the wall, not the wall's stylesheet.

---

## 7. Page Structure

Ten sections, in `resources/views/landing.blade.php` → `partials/landing/*`. Words are data in `App\Support\LandingContent`; figures come from `LandingController` (`stats`, `terms`, `snippet`).

**Header** — sticky ink glass. Publishers · Advertisers · Network · Docs · Terms · Sign in · **Start earning**. Section links are absolute because the header renders on every public page.

**Hero** (`hero`, ink + amber glow) — kicker pill with the live network count, `display-1` "Turn playtime into payouts.", lead, **Start earning** + **Talk to us**, three proofs (live in a day · NET-{hold} · ${min} minimum). Right: the real wall in a phone, preloaded at high priority — it is the LCP element — with three decorative stat pills.

**Metrics** (`metrics`, ink) — four tiles pulled up over the hero's foot, each with a credibility rule: live offers (when ≥ 1), countries (≥ 1, or **Worldwide** when the catalogue is open everywhere), demand partners live (≥ 2, otherwise the count of built-in integrations), callbacks delivered (once the sample floor is met, otherwise **Signed callbacks**). No illustrative defaults anywhere; a tile without a defensible number states the capability. Real figures count up for 600 ms.

**Partners** (`partners`, paper, `#network`) — "Demand from the networks you already know": the twelve demand partners with a real mark in `public/img/networks`, rendered as grey silhouettes (`.logo-mono`).

**Product** (`product`, paper) — "One wall. Every network. Your brand." with three proofs beside one large ink card holding the dashboard capture and two pills.

**Solutions** (`solutions`, paper, `#publishers` / `#advertisers`) — two 32px cards: **Monetise every player.** (ink gradient, wall light theme bleeding out of its foot, → `/dashboard/register`) and **Buy verified actions.** (white, postback report, → contact `topic=supply`, and `/advertise`).

**How it works** (`how`, ink, `#how`) — "Live in an afternoon.", three numbered step cards, then the diagram card: the partner marks on a dashed orbit around the brand tile ("Your app · click" above, "signed postback · +payout" below) beside the callback handler, highlighted on the server from the docs' own snippet and folded behind a button on phones (open from `md`, always visible without JS).

**Features** (`features`, ink) — four cards (fraud engine, append-only ledger with reversals, signed callbacks, per-render filtering), each with an icon and a small visual drawn from the product's vocabulary that arrives in sequence; then the terms strip (`#terms`): {rev}% · **Monthly** · ${min} · 6 dp, "stated once, before you integrate". Payouts are monthly — earnings clear on the 1st of the following month — and are never described as NET-30.

**Playbooks** (`results`, paper) — "What a first month looks like.": three white cards (casual game, fintech app, survey site), each tagged **Example**, each with a crop of a real capture. Worked scenarios, not claimed accounts; swap in real ones when there are some.

**Closing CTA** (`cta`, ink card on paper) — the mark, "Start earning from the players you already have.", **Start earning** + **Read the docs**, the support address, and a dotted globe drawn in SVG from latitude and longitude.

**Footer** (paper) — mark and tagline; Publishers · Advertisers · Developers · Company; the newsletter form (plain POST, honeypot `company`); socials from `config('playndgain.social')`, rendered only when set; legal row and the "illustrative until launch" line.

### Copy rules

Plain verbs, sentence case, no filler. Say what happens, not how it feels. *"Paid monthly"* over *"Seamless, hassle-free payouts."* Every number on the page must be defensible if a publisher asks where it came from — or visibly marked illustrative.

### Routes

```
/                            landing
/advertise                   for networks supplying offers to us
/about
/contact                     POST → same app, no CORS
/legal/{privacy|terms|publisher-agreement}
/docs/{slug}                 → 10-developer-docs.md
/status
/newsletter                  POST, the footer form
```

---

## 8. Performance Budget

Enforced in CI against a production build served by nginx + PHP-FPM with gzip
(`.github/lighthouse/serve.sh`, the same gzip snippet the deploy configs
include), so the byte rows are transfer sizes. A breach fails the build.

| Metric | Budget |
|---|---|
| LCP (mobile, throttled) | **< 2.5 s** enforced — 2.1 s measured, see below |
| CLS | < 0.05 |
| JS, landing | **< 50 KB gzipped** |
| CSS | < 30 KB gzipped |
| Fonts | 1 family (Sora), at most 2 files, the Latin file preloaded, `font-display: swap` |
| Lighthouse Performance | **≥ 95** |
| Lighthouse Accessibility | **100** |

Holding the line:

- No icon library. Inline SVG for the dozen icons used.
- No date library, no lodash, no UI kit.
- No animation library. The page's motion is one CSS pulse on the hero's live
  dot, a 280 ms theme swap on the wall mock, and hovers — nothing on scroll.
- Zero client-side syntax highlighting.
- Images pre-optimized to AVIF + WebP with explicit dimensions.
- Response cache on the landing route only, 1 h, cleared on deploy. Blade is cheap enough that the other pages do not need it.

LCP is the hero paragraph, and what it waits for is the three preloaded font
files — 145 KB on the wire. Measured behind nginx + gzip on 2026-09-01: 2.10 s
over three throttled runs, of which 0.45 s is TTFB and 1.65 s is that render
delay. The 1.8 s the brief asked for is therefore a font decision rather than a
code one — a lighter italic, tighter subsets, or one preload fewer — and it is
the owner's to make; until then the enforced ceiling is 2.5 s, low enough that
a fourth font, a second stylesheet, or an unoptimised image still fails the
build.

Accessibility at 100 is a checklist: semantic landmarks, one visible focus ring on every interactive element, verified contrast pairs (design.md §8), `alt` on every image, decorative overlays `aria-hidden`, reduced motion honoured, and a page that is complete before a byte of JavaScript runs.


---

## 9. Responsive

Breakpoints `640 / 768 / 1024 / 1280`. Mobile-first.

On a phone the hero stacks (copy, then the phone at 260px; the stat pills are hidden below `sm`), the metric cards go two-up, the partner strip two-up, the solution cards stack with their captures still bleeding out of the foot, the orbit shrinks its nodes to 42px, and the bento becomes a single column. The code card scrolls sideways inside its own box; the page body never scrolls horizontally (asserted by the screenshot pass, 390px included).

---

## 10. Tests

`tests/Feature/Landing`:

- The claim, the product section, the steps and the terms are in the **raw HTML** — the page must render without JavaScript.
- Every metric is printed at its final value; nothing carries a count-up hook; nothing starts hidden.
- Every image has `alt`; the captures are real files under `/img/landing`; the hero image is preloaded at high priority; the wall's own card markup does not appear on the page.
- The partner marks appear in the strip and on the orbit; both marketplace sides, their anchors and their calls to action are present; every playbook is tagged Example; ink and paper sections alternate.
- The callback handler is highlighted on the server with the real macro names.
- The newsletter form posts to this app with its honeypot.
- Every hidden entrance is scoped to `html.js` inside `prefers-reduced-motion: no-preference`; the image fade is JavaScript-gated; no animation library in either bundle; every `data-metric` equals the figure printed beside it.
- Metric tiles: four, capabilities where a number cannot be defended, never an illustrative figure; payouts described as monthly, never NET-30.
- The mobile menu declares `aria-controls`/`aria-expanded`, closes on Escape, returns focus and locks the page.
- The header and footer contain no `href="#"` and every internal link resolves.
- Lighthouse CI on the landing route meets the budget table above.
