# AdVault — Design Rules

The rules every surface follows: landing, offerwall, admin panel, publisher panel.
Source of truth for tokens is `resources/css/tokens.css`. This file explains it;
the CSS file is what ships. Where they disagree, the CSS is right.

Design intent and the reasoning behind it: [docs/09-landing-site.md](docs/09-landing-site.md).

---

## 1. One token file

```
resources/css/tokens.css          @font-face · @theme · .display · .display-x · .num
├── resources/css/app.css         landing + docs
├── resources/css/filament/theme.css   admin + publisher panels
└── (phase 2) offerwall entry
```

Two token files always drift. Nothing surface-specific goes in `tokens.css` — no
layout, no components, no page chrome. If only one surface needs it, it belongs
in that surface's stylesheet.

## 2. Colour

| Token | Value | Use |
|---|---|---|
| `obsidian-950` | `#08090A` | page |
| `obsidian-900` | `#0E1013` | raised section |
| `steel-850` | `#14171B` | card |
| `steel-800` | `#1A1E24` | card, elevated |
| `steel-700` | `#262B33` | border |
| `steel-600` | `#363D47` | border, strong |
| `chalk` | `#E9ECF1` | primary text |
| `steel-300` | `#A2ABB8` | secondary text |
| `steel-400` | `#6E7784` | muted, labels, timestamps |
| `amber-500` | `#F5A524` | brand, primary action |
| `amber-400` | `#FFBE4D` | brand, hover |
| `amber-950` | `#2A1C05` | brand, tinted surface |
| `gain` | `#2FBF71` | positive money **only** |
| `loss` | `#E5484D` | negative money **only** |

**The rule that carries the system: green and red mean money direction and
nothing else.** No green success toasts, no green checkmarks in a feature grid,
no red decorative accents. When green appears on an AdVault surface, the
publisher earned something. Use amber for attention and steel for state.

Surfaces are cool near-black, never pure `#000`. Dark mode is not a mode — it is
the product. The panels force it; there is no light theme to keep in sync.

## 3. Type

Two families, three roles. Self-hosted `woff2` in `public/fonts`, `font-display:
swap`, latin + latin-ext (4 files). **No font CDN** — one origin, and no
third-party request on a site that talks about privacy.

| Role | Class | Face |
|---|---|---|
| Display | `.display` (`wdth 112`), `.display-x` (`wdth 125`) | Archivo variable |
| Body / UI | default `font-sans` | Archivo |
| Data, money, code, timestamps | `.num` | Geist Mono, `tabular-nums` |

The width axis is load-bearing. `font-stretch: 62% 125%` must stay on the
`@font-face` or the browser clamps the axis to 100% and every heading silently
flattens to normal-width Archivo.

**Every monetary figure everywhere is `.num`.** `$0.130000` above `$2.520000`
aligns on the decimal. This one rule does more for perceived seriousness than any
illustration. It applies to tables, badges, form inputs that take money, and
anything with 6 decimal places — money is `decimal(14,6)` and the UI shows all
six. No silent rounding.

Display tracking `-0.02em`. Body line-height 1.6, headings 1.15, measure capped
at ~68 characters.

## 4. Shape and depth

Radius: `6px` inputs · `10px` cards · `14px` panels · `999px` pills. Nothing
larger — `rounded-3xl` everywhere is the signature of the template look this
brand exists to avoid.

Spacing scale, 8px based: `4 8 12 16 24 32 48 64 96 128`. Container `1280px`.

**Elevation comes from borders and surface value, not shadow.** On a near-black
page a drop shadow is invisible; a `1px` `steel-700` border against a lighter
surface reads as raised and stays crisp at any zoom. Cards lift toward the light
(`.lift`) instead of casting a shadow. One shadow exists in the whole system: the
sticky nav once scrolled.

## 5. Motion

GSAP + ScrollTrigger on the marketing site only — three places, listed in
docs/09 §5. Alpine handles everything interactive. **The offerwall never loads
GSAP** (`resources/js/wall.js` is Alpine only; asserted in
`tests/Feature/Landing/BundleTest.php`).

```css
--dur-fast: 120ms;   /* hover, focus */
--dur-base: 200ms;   /* enter, expand */
--dur-slow: 320ms;   /* section reveal */
--ease-out: cubic-bezier(.2, .8, .2, 1);
```

Not doing: parallax, section pinning, scroll-jacking, spring overshoot, bounce,
hover tilt, marquees, mouse-following gradients. A vault door is heavy, not
springy.

**`prefers-reduced-motion: reduce` is a code path, not a courtesy.** Animations
are skipped, not shortened; final state is applied immediately.

## 6. The rule that outranks the rest

**Every page renders completely with JavaScript disabled.**

Reveals start at full opacity in CSS and are hidden by JS on mount
(`.js-ready .reveal`), never the other way round. Counters are server-rendered at
their real value and knocked back to zero by JS before counting in. A page whose
every section starts at `opacity: 0` in the stylesheet is a page that disappears
when a script fails to load.

Corollary: no client-side syntax highlighting, no client-side routing, no content
that only exists after hydration.

## 7. Accessibility, non-negotiable

- Semantic landmarks, one `h1`, headings in order.
- Visible focus ring on every interactive element: `2px` `amber-500`, `3px` offset.
- 4.5:1 contrast, verified not assumed. Code comments carrying integration
  warnings are `steel-300` italic — they were `steel-600` at 2.2:1 and unreadable.
- `alt` on every image. Decorative images take `alt=""` and `aria-hidden`.
- Purely decorative duplicates of on-page content (the trace rail) are
  `aria-hidden` — a screen reader should not read the same log twice.
- Keyboard-reachable tabs, accordions, and menus. `<details>` over a JS accordion.

## 8. Numbers

Only real numbers ship. A fabricated counter is the fastest way to lose a
publisher who checks. Every figure on a public page must be defensible if
someone asks where it came from; anything illustrative says so on the page.

## 9. What we are deliberately not doing

Violet-to-pink gradients · `rounded-3xl` white cards on a lilac wash · floating
3D isometric coins · stock photography of smiling teams · partner logo strips ·
carousels · decorative squiggles · icon libraries · benefit-promising feature
cards ("best-in-class fraud detection" is what the competitors write; "17 rules,
every block explained" is what a technical buyer believes).

## 10. Applying it to the panels

The Filament panels are themed, not restyled per component:

- `resources/css/filament/theme.css` imports `tokens.css`, so the panels get the
  same faces and the same palette from the same file as the site.
- Panel providers set `->colors(['primary' => …amber])`, load fonts through
  `LocalFontProvider` (no Google Fonts request), and force dark mode.
- Auth pages (login, register, password reset) share the marketing site's split
  shell: form left, the trace on the vault wall right. Signing in should land
  somewhere familiar.
- Filament components are used as they come. If a component needs the brand, fix
  it in the theme file for every instance — never with a one-off class in one
  resource.
