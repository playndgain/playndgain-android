# 09 — Brand & Landing Site

**Blade + Alpine.js 3 + Tailwind v4 + GSAP 3.** Server-rendered HTML. No React, no Inertia, no SSR process, no Node in production.

---

## 1. Brand: AdVault

### The idea

A vault is where value is held, secured, and released on terms. That is exactly what this product does with a publisher's earnings — holds them through a chargeback window, protects them with a fraud engine, releases them on NET-30.

The identity has a spine that is not decoration: **weight, security, released value.**

### The audience, named

Not "publishers" in the abstract. The person deciding where to send a million clicks a month: technically competent, running a rewards app or a GPT site, already integrated with two other offerwalls, and evaluating whether yours is worth the engineering hours. They have seen the treasure-chest pitch ten times.

**The page's single job:** convince that person the money path is correct and the integration is short.

### What we are deliberately not doing

Every offerwall platform in this vertical looks identical: violet-to-pink gradient, `rounded-3xl` white cards on a lilac wash, floating 3D isometric coins, soft drop shadows. The reference screenshots are exactly this. It reads as a template because it is one.

AdVault goes the other way: **dark, dense, engineered, financial.** Closer to a trading terminal than a rewards app.

> **The trap on the other side.** "Near-black background with one bright accent" is itself a default — it shows up on AI-generated design regardless of subject. Dark + amber is fixed by the brief, so the distinctiveness cannot come from colour. It comes from **structure and signature** (§2) and from **type** (§4). If those are generic, the page is generic no matter how good the palette is.

### Palette

```css
:root {
  /* Surfaces — cool, near-black, never pure #000 */
  --obsidian-950: #08090A;   /* page */
  --obsidian-900: #0E1013;   /* raised section */
  --steel-850:    #14171B;   /* card */
  --steel-800:    #1A1E24;   /* card, elevated */
  --steel-700:    #262B33;   /* border */
  --steel-600:    #363D47;   /* border, strong */

  /* Text */
  --chalk:        #E9ECF1;   /* primary */
  --steel-300:    #A2ABB8;   /* secondary */
  --steel-400:    #6E7784;   /* muted, labels, trace timestamps */

  /* Brand — amber. Gold in a vault. */
  --amber-500:    #F5A524;
  --amber-400:    #FFBE4D;
  --amber-950:    #2A1C05;

  /* Semantic — reserved, never decorative */
  --gain:         #2FBF71;   /* earnings, positive money ONLY */
  --loss:         #E5484D;   /* reversals, negative money ONLY */
}
```

**The colour rule that carries the system:** green and red mean money direction and nothing else. No green success toasts, no green checkmarks in the feature grid, no red decorative accents. When green appears on any AdVault surface, the publisher earned something.

Tokens live once, in `resources/css/tokens.css`, imported by the site, the offerwall, and the Filament panel theme. Two token files always drift.

---

## 2. Signature: The Trace

The one element this page is remembered by.

### What it is

A real conversion, played end to end, in the log vernacular this domain actually speaks:

```
14:32:07.221   click        US · Android · 8f3a91c4…
14:32:07.229   →  OGAds     redirect
14:34:51.006   ←  convert   txid 19624243          $0.130000
14:34:51.014   ledger       your share (70%)       $0.091000
14:34:51.109   →  postback  yoursite.com  200      94ms
                                          ──────────────────
                                          user credited      91 Coins
```

Mono, tabular figures, timestamps in `--steel-400`, money in `--chalk`, the final credit in `--gain`.

### Why this and not a headline over an illustration

It is the product. Not a metaphor for the product, not an illustration of the product — the actual artifact the buyer will be reading in their own logs next week. Everyone else in this vertical shows a treasure chest because they sell to non-technical publishers. Showing the trace is a filter: it speaks to the buyer worth having.

### How it runs the page

The trace does not stop at the hero. It becomes a **sticky left rail** for the whole document — a hairline with timestamped events pinned to it. As you scroll, a playhead advances down the rail and the next event lights up. Each section of the page attaches to one step in the conversion lifecycle:

| Scroll position | Trace event | Section |
|---|---|---|
| Hero | `click` | The trace itself |
| 2 | `redirect` | How it works |
| 3 | `convert` | The offerwall |
| 4 | `ledger` | Why AdVault |
| 5 | `postback` | Integration |
| 6 | `payout` | Payouts + calculator |

**Timestamps, not `01 / 02 / 03`.** Numbered markers are decoration unless the content is genuinely a sequence. This content is a sequence, and the timestamp is the domain's own artifact — it encodes something true (elapsed time through the money path) instead of just counting sections.

### Mobile

The rail collapses to a 2px progress line at the left edge with the current event label pinned above the section heading. No horizontal scroll, no shrunken mono.

---

## 3. Layout

Asymmetric. Left rail + content column. Never centred — a centred hero with a gradient orb behind it is the shape the page is avoiding.

```
┌──────────────────────────────────────────────────────────────┐
│  ▲ AdVault          Why  Docs  Advertise        [ Dashboard ]│
├────────┬─────────────────────────────────────────────────────┤
│        │                                                     │
│ 07.221 │  14:32:07.221  click       US · Android · 8f3a91c4… │
│   │    │  14:32:07.229  →  OGAds    redirect                 │
│ 07.229 │  14:34:51.006  ←  convert  txid 19624243   $0.130000│
│   │    │  14:34:51.014  ledger      your share      $0.091000│
│ 51.006 │  14:34:51.109  →  postback yoursite  200      94ms  │
│   │    │                            ────────────────────────  │
│ 51.014 │                            user credited    91 Coins│
│   │    │                                                     │
│ 51.109 │  One conversion, start to finish. 2m 43s.           │
│   │    │  Aggregated offers from a dozen networks.           │
│   ▼    │  One iframe. Signed callbacks. NET-30.              │
│        │                                                     │
│        │  [ Start earning ]   Read the docs →                │
│        │                                                     │
├────────┼─────────────────────────────────────────────────────┤
│  live  │   1,284 offers   ·   134 countries   ·   6 networks │
└────────┴─────────────────────────────────────────────────────┘
```

Container `max-width: 1280px`. Rail `96px`, content the remainder. 8px spacing scale: `4 8 12 16 24 32 48 64 96 128`.

Radius: `6px` inputs · `10px` cards · `14px` panels · `999px` pills. Nothing larger — `rounded-3xl` everywhere is the signature of the look being avoided.

**Elevation from borders and surface value, not shadow.** On a near-black page a drop shadow is invisible; a `1px --steel-700` border against a lighter surface reads as raised and stays crisp at any zoom. One shadow exists in the whole system: the sticky nav when scrolled.

---

## 4. Typography

Three roles, two families. Self-hosted `woff2` in `public/fonts`, subset Latin + Arabic. No Google Fonts CDN — one origin, and no third-party request on a site that talks about privacy.

| Role | Face | Why this one |
|---|---|---|
| Display | **Archivo Expanded** (variable, width axis) | Wide industrial grotesk, screen-native. The expanded width axis is rarely used and is what makes headings unmistakable. |
| Body / UI | **Archivo** (normal width) | Same family, different width. One typeface doing two jobs — coherent and economical. |
| Data / trace / code | **Geist Mono** | Tabular figures by default. Carries the signature element, so it is doing real work, not captioning. |

**Every monetary figure everywhere — landing, docs, both dashboards, the offerwall — is mono with `tabular-nums`.** `$0.130000` above `$2.520000` aligns on the decimal. This single rule does more for perceived seriousness than any illustration, and it is why the dashboards will not read as the reference screenshots even sharing a layout.

**Scale** (1.25, clamped fluid):

```css
--text-xs:   0.75rem;    /* trace timestamps, labels */
--text-sm:   0.875rem;   /* secondary, trace body */
--text-base: 1rem;
--text-lg:   1.125rem;   /* hero caption */
--text-xl:   1.5rem;     /* card titles */
--text-2xl:  2rem;       /* section heads */
--text-3xl:  clamp(2.25rem, 4vw, 3rem);
```

There is no `--text-4xl`. **The hero has no giant headline** — the trace is the largest thing above the fold and the claim sits under it at `--text-lg`. That is the risk this design takes, and the justification is in §2.

Display tracking `-0.02em`, width axis `112` for section heads and `125` for the few places a heading needs to dominate. Body line-height 1.6, headings 1.15, measure capped at 68 characters.

---

## 5. Motion

**GSAP + ScrollTrigger**, used in three places and nowhere else. Alpine handles everything interactive.

| Where | What |
|---|---|
| **Hero trace, on load** | Chips reveal in sequence, ~110ms apart, each a 14px rise + fade. Total ~1.2s, then still. Reads as a log streaming in, not an animation playing. |
| **The rail, on scroll** | ScrollTrigger scrubs the playhead down the rail. Events light from `--steel-400` to `--amber-500` as they pass. The only scrubbed animation on the page. |
| **Counters** | Stat figures count up once on entering view. Mono tabular, so no width jitter while ticking. |
| **The phone earns while you read** | Every ~7s: a card takes an amber ring, a `+640` floats up, the coin balance counts to its new value. Paused by ScrollTrigger whenever the hero is off screen — no work in a background tab. |
| **Hero wallpaper drift** | 52s alternating scale + translate on the vault image. Slow enough that it never reads as an animation, only as the page not being static. |

Everything else is CSS: `x-intersect` adds a class, a transition runs. An 8px rise plus a fade, once, on first view.

### Life without stock photography

The first build was correct and lifeless — flat cards on flat dark, nothing moving after load. Three fixes, none of them decorative:

**1. App icons, not gradient squares.** The offerwall mock used flat gradient tiles where app art belongs, and it made the hero read as a wireframe rather than a product. Now: gradient tile + specular highlight + inset border + an SVG glyph from a `<symbol>` sprite. *In production these are real icons from the networks' CDNs (`offers.icon_url`)* — the sprite exists so the prototype is honest about what goes there.

**2. A hero wallpaper with real material.** `prototype/img/hero-vault.webp` — an extreme macro of a machined steel vault door: concentric tooling marks, a warm amber light raking in low from the upper right catching the edge as a thin rim, falling off fast into darkness across the lower left, cool blue-steel bounce in the deepest shadow.

It is generated (Higgsfield, `nano_banana_pro`), and it earns its place because the vault surface is the brand's own material rather than decoration borrowed from stock. Two rules make it safe behind content:

- **It falls off to page colour.** A radial plus a linear gradient over the image drive it to `--obsidian-950` by 78% height, so the headline never sits on texture and the hero dissolves into the next section with no seam.
- **It drifts.** 52s alternating scale and translate — slow enough that it never reads as an animation, only as the page not being static.

**Delivery:** 1.5 MB PNG → **47 KB** WebP at 1920, plus a **15 KB** 960 variant served by `srcset` to phones. Preloaded with `fetchpriority="high"` since it is the LCP element.

**3. Ambient light that drifts.** Two blurred colour fields on long, offset, alternating cycles so the loop never reads as a loop. Amber above, a cool blue-steel below — the cool layer is what stops the page from looking like a single-hue gradient wash. Both are `animation: none` under reduced motion.

**Layer order in the hero is explicit** (`hero-bg` 0 → `grain::before` 1 → `aurora` 2 → content 10). A parent's `::before` otherwise paints beneath its element children, and the grain texture disappears under the wallpaper.

**Cut after review:** the live conversion ticker. It worked, but with the wallpaper carrying atmosphere and the phone already earning on its own, a third moving thing was the accessory to remove — and a scrolling feed of numbers sat awkwardly next to a page whose whole claim is that its numbers are auditable.

**Deliberately not added:** stock photography of smiling teams. It is what the reference page uses and it is exactly what would collapse this direction into the template it is avoiding.

```css
--dur-fast: 120ms;   /* hover, focus */
--dur-base: 200ms;   /* enter, expand */
--dur-slow: 320ms;   /* section reveal */
--ease-out: cubic-bezier(.2, .8, .2, 1);
```

**Not doing:** parallax, section pinning, scroll-jacking, spring overshoot, bounce, hover tilt, marquees, mouse-following gradients. A vault door is heavy, not springy.

### Reduced motion is a code path, not a courtesy

```js
if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  gsap.set('.trace-line', { opacity: 1, y: 0 });   // final state, immediately
  ScrollTrigger.getAll().forEach(t => t.kill());
  document.querySelectorAll('[data-count]').forEach(el => el.textContent = el.dataset.count);
} else {
  buildTimeline();
}
```

Reveals begin at **full opacity in CSS** and are set to hidden by JS on mount. If GSAP fails to load, blocked, or errors, the page is fully readable. A page whose every section starts at `opacity: 0` in the stylesheet is a page that disappears when a script fails.

### Logo

Wordmark `AdVault` in Archivo Expanded Medium, `-0.02em`, **V** rendered as a vault-door chevron — two strokes with an amber-to-steel gradient on the inner edge. Monochrome variant mandatory, must survive at 16px favicon. Clear space equal to the wordmark's cap height.

---

## 6. Stack

| Concern | Choice |
|---|---|
| Templates | Blade. Server-rendered. |
| Interactivity | Alpine.js 3.16 + `@alpinejs/intersect` + `@alpinejs/collapse` |
| Animation | GSAP 3.15 + ScrollTrigger, deferred |
| Styling | Tailwind v4.3, CSS-first config, tokens as `@theme` |
| Build | Vite 8 + `laravel-vite-plugin` 3 (its `vite` peer range is `^8.0.0` — the two versions move together) |
| Content | Markdown → Blade for docs and legal ([10-developer-docs.md](10-developer-docs.md)) |

### Why this over Inertia + React

| | Blade + Alpine | Inertia + React |
|---|---|---|
| Prod runtime | PHP. Nothing else. | PHP **+ a Node SSR process** under supervisor |
| SEO | Native — it is HTML | Only with SSR running |
| Failure mode | None to have | Renderer dies → page silently drops out of search |
| JS shipped | ~49 KB gz | ~140 KB gz |
| Stacks in the app | 2 (Alpine, Livewire 4) | 3 (Alpine, Livewire 4, React) |
| Offerwall shares it | **Yes — same Alpine** | No |

The offerwall already runs Blade + Alpine. Using it for the marketing site adds nothing to the app; adding React would have added a third frontend stack and a long-lived process that can die without anyone noticing.

**Node is required at build time only.** `npm run build` produces hashed assets in `public/build`. Production runs nginx + PHP-FPM + MySQL + Redis, and that is the entire list.

### Bundle

| | gzipped |
|---|---|
| Alpine 3 + intersect + collapse | ~17 KB |
| GSAP core | ~23 KB |
| ScrollTrigger | ~11 KB |
| App JS | ~6 KB |
| **Total** | **~57 KB** |

GSAP loads `defer` and the trace timeline builds on `DOMContentLoaded`. Nothing above the fold waits on it — the trace is in the HTML, fully readable, before a byte of JS runs. The animation is an enhancement to content that already exists.

### Entry points

```js
// vite.config.js
laravel({
  input: [
    'resources/css/app.css',    // landing + docs
    'resources/js/site.js',     // Alpine + GSAP
    'resources/css/wall.css',   // offerwall — separate, minimal
    'resources/js/wall.js',     // Alpine only, no GSAP
  ],
  refresh: true,
})
```

**The offerwall never loads GSAP.** It is a list of cards on a mid-range Android over 3G. Separate entry, separate budget.

---

## 7. Page Structure

> **A working prototype of this page exists at `prototype/landing.html`.** Single file, real Tailwind + Alpine + GSAP over CDN, openable in a browser. It is the reference implementation for everything below — where this document and the prototype disagree, the prototype is right.

The information architecture follows the shape proven in this vertical (ayeT-Studios' page is the clearest example of it): eyebrow stat pill → hero with a device surrounded by floating cards → metric strip → how-it-works → full-bleed colour band → feature grid → integration → FAQ → two-tone closing CTA → fat footer.

**What was taken from that shape:** the composition, the band rhythm, the grid, the closing two-tone headline.

**What was replaced, and why:**

| Their version | Ours | Reason |
|---|---|---|
| Floating cards reading *"D30 ROAS 100%"*, *"12M+"* | Floating cards reading the **actual trace events** of one conversion | Same composition, real content. Marketing claims are what every competitor's cards say. |
| Underlined highlight word in the headline | Colour shift on the second clause | The underline treatment is on half the B2B pages on the internet. |
| Partner logo strip | Live metric strip | Most templated element on any B2B page, and we do not have permission to display partner marks. |
| Product carousel with arrows | Static two-up | A carousel hides content behind an interaction most visitors never perform. |
| Decorative squiggles, stock smiling-people photos | Nothing | Removed rather than replaced. |
| Feature cards promising benefits | Feature cards naming mechanisms and numbers | "Best-in-class fraud detection" is what the competitors write. "17 rules, every block explained" is what a technical buyer believes. |

Each section answers the question the previous one raises, and each attaches to a trace event.

**Hero · `click`**
The trace, largest element on screen. Under it, at `--text-lg`: *"One conversion, start to finish. 2m 43s. Aggregated offers from a dozen networks. One iframe. Signed callbacks. NET-30."* Two CTAs — **Start earning** (amber) and *Read the docs →* (ghost).

**Counters**
Three live figures in mono: offers available, countries covered, active networks. Real query results, cached hourly:

```php
Cache::remember('landing.stats', 3600, fn () => [
    'offers'    => Offer::where('status', 'active')->count(),
    'countries' => Offer::activeCountryCount(),
    'networks'  => Network::where('status', 'active')->count(),
]);
```

Only real numbers ship. A fabricated counter is the fastest way to lose a publisher who checks.

> **Cut deliberately:** the partner-logo trust strip. It is the single most templated element on any B2B page, and displaying partner marks needs permission we do not have. The counters do the same job with numbers we can defend.

**How it works · `redirect`**
Three steps: **Embed** (the iframe snippet, highlighted server-side, copy button) → **Users complete offers** → **Get paid**. Real artifacts, not icons. The snippet is the same one in the docs quick start, from the same source file.

**The offerwall · `convert`**
Wide product shot. Three callouts: per-app theming, per-user offer filtering, fraud checks before the redirect. A live theme switcher — Alpine sets CSS custom properties on a mock wall, no round trip.

**Why AdVault · `ledger`**
Four cards, each one concrete claim and one number:

- **NET-30, no surprises** — hold window and terms, stated.
- **Fraud caught before it costs you** — rule count, what gets blocked.
- **A dozen networks, one integration** — offer count, dedupe explained.
- **Callbacks you can verify** — the signature scheme, in code.

Each names a mechanism. "Best-in-class fraud detection" is what the competitors write; naming the rules is what a technical buyer believes.

**Integration · `postback`**
Tabbed code (PHP / Node / Python / cURL) beside the macro table. Alpine `x-show` swaps panes. **All highlighting is done server-side** — zero highlighter in the client bundle.

**Payouts · `payout`**
Terms as a table: $20 minimum · NET-30 · PayPal, crypto, bank, Wise · manual review with a stated turnaround. Below, a payout calculator — volume and conversion rate in, monthly estimate out, in mono. Alpine, instant, disclaimer directly under it rather than in a footnote.

**FAQ**
`<details>`/`<summary>` with `x-collapse` for the animation. Real HTML — indexable, keyboard-accessible, works before JS.

**Final CTA** — one line, one amber button. No form.

**Footer** — product · developers · company · legal · status. Privacy and terms are written pages, not placeholders.

### Four things the prototype proved

Found by building it, not by planning it:

1. **The trace cannot orbit the device below 1024px.** Chips overlap each other and the phone. Below `lg` the composition becomes a vertical timeline — and since DOM order is already chronological, that reads *better* on a phone than the desktop arrangement does.
2. **The fixed trace rail collides with the light bands.** It is dark-on-dark by design. Light sections carry `data-light` and a ScrollTrigger fades the rail out across them. The rail also only appears at `2xl`, below which there is no margin to put it in.
3. **Tailwind v4 centres via the `translate` property, not `transform`.** `transform: none` in a media query does not undo `-translate-x-1/2`; you need `translate: none`. Cost an iteration.
4. **Code comments carry the warnings.** They were set in `--steel-600` at roughly 2.2:1 — the two sentences that prevent the most common integration failures, unreadable. They are `--steel-300` italic now.

### Copy rules

Plain verbs, sentence case, no filler. Say what happens, not how it feels. *"Get paid on NET-30"* over *"Seamless, hassle-free payouts."* Every number on the page must be defensible if a publisher asks where it came from.

### Routes

```
/                            landing
/advertise                   for networks supplying offers to us
/about
/contact                     POST → same app, no CORS
/legal/{privacy|terms|publisher-agreement}
/docs/{slug}                 → 10-developer-docs.md
/status
```

---

## 8. Performance Budget

Enforced in CI against a production build. A breach fails the build.

| Metric | Budget |
|---|---|
| LCP (mobile, throttled) | **< 1.8 s** |
| CLS | < 0.05 |
| JS, landing | **< 70 KB gzipped** |
| CSS | < 30 KB gzipped |
| Fonts | 2 families, 4 files, preloaded, `font-display: swap` |
| Lighthouse Performance | **≥ 95** |
| Lighthouse Accessibility | **100** |

Holding the line:

- No icon library. Inline SVG for the dozen icons used.
- No date library, no lodash, no UI kit.
- GSAP `defer`, timeline built after DOM ready.
- Zero client-side syntax highlighting.
- Images pre-optimized to AVIF + WebP with explicit dimensions.
- Response cache on the landing route only, 1 h, cleared on deploy. Blade is cheap enough that the other pages do not need it.

Accessibility at 100 is a checklist: semantic landmarks, visible focus rings on every interactive element, 4.5:1 contrast (amber-on-obsidian verified, not assumed), keyboard-reachable tabs and accordion, `alt` on every image, reduced motion honoured, and the trace rail marked `aria-hidden` since it is decorative once its content is in the document flow.

---

## 9. Responsive

Breakpoints `640 / 768 / 1024 / 1280`. Mobile-first.

The trace rail collapses to a 2px progress line at the left edge below 1024px. The hero trace stays mono and readable — it drops the redirect line and the elapsed-time column rather than shrinking the font. Code tabs become a horizontally scrollable strip inside their own container; the page body never scrolls sideways.

---

## 10. Tests

- Landing returns 200 and the hero trace text is present in the **raw HTML** — it must render without JS.
- With JS disabled, every section is visible and no content sits at `opacity: 0`.
- `prefers-reduced-motion: reduce` → ScrollTrigger instances are killed and counters show final values.
- Landing stats are real query results, not literals.
- The offerwall bundle does not contain GSAP — assert against the built manifest.
- Contact form validates and returns errors to the Blade view.
- Lighthouse CI on the landing route meets the budget table above.
