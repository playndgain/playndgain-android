# Brand Second Pass — Polish & Decisions

> **For agentic workers:** this is a **delta over a finished rebrand**. Do not restart, re-import
> Advault, or discard anything in the working tree. Read this file top to bottom, run the baseline
> gate, then execute the tasks in order. Use superpowers:executing-plans if available.

> **Status (2026-09-01, executed):** Tasks 1–5 landed and the gate is green — the owner chose
> Option A for Task 3 (no active offers = pre-launch, inventory figures fall back to the
> illustrative defaults; fraud rules stay real). Task 6 is the owner's list and is untouched. Task 4 went
> further than the file list: production had no gzip directives at all, so
> `deploy/nginx/snippets/playndgain-gzip.conf` is new and included by the site and wall blocks,
> `.github/lighthouse/serve.sh` serves CI through it, and `lighthouserc.json` lost the `//` keys
> inside `assertions` (LHCI reads them as audit ids and fails). Measured behind nginx + gzip:
> performance 0.99, accessibility 1.0, CSS 15 KB / JS 17.5 KB transfer, LCP 2.10 s — the font
> bytes, so the LCP ceiling is 2.5 s with the measurement documented beside it (docs/09 §8).

**Goal:** Close the remaining gaps between the delivered PLAYNDGAIN rebrand (2026-09-01) and the
"Landing Editorial v2" design board / the original brief, without changing product behaviour.

**Architecture:** unchanged. Tokens live once in `resources/css/tokens.css` (`@theme static`), faces
in `resources/css/fonts.css` (site + panels) and `resources/css/fonts-wall.css` (wall). Three Vite
entries: `resources/css/app.css` + `resources/js/site.js` (landing, docs, legal, contact),
`resources/js/wall.js` → `wall.css` → `wall-ui.css` (the iframe, its own document and bundle),
`resources/css/filament/theme.css` (both panels). Brand mark: `resources/views/components/brand-mark.blade.php`;
favicons: `php scripts/make-favicons.php`. Wall routes: `routes/wall.php`, registered from
`bootstrap/app.php` outside the `web` group (stateless by specification).

**Tech Stack:** Laravel 13 · PHP 8.5 · Filament 5 · Livewire 4 · Tailwind 4 · Vite 8 · Alpine 3
(+ `@alpinejs/collapse`) · `motion/mini` (wall only) · Pest 5 · MySQL · Redis.

**Design reference:** Claude Design project `9a8f02d4-9d43-48a2-bf3f-bc8b3718ffbe`, file
`Landing Editorial v2.dc.html` (share link:
https://claude.ai/design/p/9a8f02d4-9d43-48a2-bf3f-bc8b3718ffbe?file=Landing+Editorial+v2.dc.html&via=share).
Readable from Claude Code through the `DesignSync` tool after `/design-login`; `WebFetch` returns 403.
The written brand rules are `design.md`; `docs/09-landing-site.md` §1–§5 describe the page.

**State when this plan was written:** import commit `813064b` ("Import Advault codebase as the
Playndgain starting point") plus the whole rebrand **uncommitted** in the working tree (~244 paths).
Gate green: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 5), `vendor/bin/pest`
**1095 passed / 1 skipped**, `npm run build`. Lighthouse on `/` through `php artisan serve`:
performance 93, accessibility 100, best-practices 100, SEO 100. The delivery report, intentional
differences and open items were given to the owner in the session that produced this file.

---

## Global constraints

- `/Users/ziad/LocalSites/advault` is **read-only reference**. Never modify it.
- Every task edits the existing tree. Nothing is re-imported, regenerated from Advault, or reverted
  wholesale.
- No new dependencies. No scroll choreography — the board is still (one 3 s pulse on the hero dot,
  a 280 ms theme swap, hovers). No gradients, glass, or rounded-3xl anything.
- Keep the wall's per-app theming contract (`--wall-primary`, `--wall-on-primary`, `--wall-bg`,
  `--wall-card`, `--wall-text`, `--wall-radius`; `App\Services\Wall\WallContext`) and the selector
  inventory of `wall-ui.css` — `tests/Feature/Wall`, the publisher live preview
  (`resources/views/filament/publisher/wall-preview.blade.php`) and the landing mocks depend on both.
- Every public page must render completely with JavaScript disabled; reduced motion is a code
  path; focus rings come from `tokens.css`; contrast ≥ 4.5:1 (Lighthouse accessibility must stay 100).
- **Response cache:** `/` is cached in Redis for an hour. Run `php artisan responsecache:clear`
  after every `npm run build`, or the cached HTML points at asset hashes that no longer exist.
- **Tests:** run Pest only against `playndgain_test` (phpunit.xml's default). Never run a test with
  `DB_DATABASE=playndgain` — `RefreshDatabase` wipes the seeded local database. If you need a
  rendered authenticated page, bootstrap the HTTP kernel in a plain PHP script instead.
- **Local environment (EnvKit):** `https://playndgain.test` serves the app. Demo wall:
  `/wall?appid=10000&userid=USER_1&country=US` (test mode, so `&country=DE` shows consent,
  `&theme=light`, `&lang=ar` work). Demo publisher `demo@playndgain.test` / `password`. Admin
  `admin@playndgain.com` — the password is printed once by the seeder; if it is lost, reseed with
  `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoWallSeeder` (local only).
- **Screenshots:** headless Chrome clamps `--window-size` below ~500 px. For phone widths use
  Puppeteer (`puppeteer-core` against `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`,
  `page.setViewport({ width: 390 })`, `fullPage: true`).

## Baseline — run first

```bash
composer ci                                   # pint --test · phpstan · pest
npm run build && php artisan responsecache:clear
```

Expected: everything green, 1095 passed / 1 skipped. If the baseline is not green, stop and report
before touching a task.

---

## Task 1 — Keep the hero's italic clause together

**File:** `resources/views/partials/landing/hero.blade.php`
**Problem:** at ≥ 1280 px the H1 breaks "…already playing. *Get* / *paid for it.*" — the gold clause
splits and "Get" is orphaned. The board breaks after "playing." with the gold clause whole.
**Do:** wrap the `<em>` in a `whitespace-nowrap` span (or place `<br class="hidden lg:block">`
before it) and keep `max-w-[21ch]`. Verify 1280 / 1440 / 1536 (clause on one line, no orphan) and
390 / 768 (no horizontal overflow, natural wrapping).
**Check:** `tests/Feature/Landing/LandingPageTest.php` still asserts both hero sentences in the raw HTML.

## Task 2 — Bare reward figure on the two landing mocks

**Files:** `resources/views/partials/landing/wall-card.blade.php`,
`resources/views/wall/partials/card.blade.php`
**Problem:** the mocks render the real wall card, so rows read `+640 COINS`; the board shows a bare
`640`.
**Do:** add an opt-in `bare` variable to the card partial (default `false`) that omits the `+` and
the currency label; pass `'bare' => true` from the two landing mocks only. The live wall and the
publisher preview keep `+640 COINS`. Keep the `.wall-reward`, `.wall-reward__row`,
`.wall-reward__value`, `.wall-reward__currency` classes for the non-bare path.
**Check:** `LandingPageTest` still finds `class="wall-card` on `/`; add an assertion that `/` does not
contain `+640`; `tests/Feature/Wall` and `tests/Feature/Panel/PublisherPanelTest.php` unchanged and green.

## Task 3 — Zero-inventory fallback for the landing figures (owner decision first)

**Files:** `app/Http/Controllers/LandingController.php`, `resources/views/landing.blade.php`,
`resources/views/partials/landing/{hero,network,counters,platform,wall}.blade.php`,
`tests/Feature/Landing/LandingStatsTest.php`
**Problem:** a fresh install prints "Offers in 0 countries", "one network of demand",
"Smart routing across 1 network" — honest, but it reads as broken before launch.
**Option A (recommended):** when the active-offer count is 0, treat the install as pre-launch and
use the illustrative defaults already in `landing.blade.php` (offers 1284, countries 134,
networks 12, postback 94 ms, delivery 99.98 %, rules from the real table). The footer already says
"Figures on this page are illustrative until launch." **Option B:** keep the live zeros.
**Do:** ask the owner; implement A only if approved. Cover both branches in `LandingStatsTest`
(seeded inventory → live figures; empty inventory → defaults).

## Task 4 — Make the Lighthouse CI job measure what production serves

**Files:** `.github/workflows/ci.yml`, `lighthouserc.json`
**Problem:** the byte budgets (`resource-summary:stylesheet:size` 30 KB, `:script:size` 50 KB) are
written for gzip transfer sizes, but the job serves through `php artisan serve`, which sends the
76 KB stylesheet uncompressed and fails the assertion (Advault's 86 KB stylesheet failed it too).
LCP under the same conditions is ~3 s against an 1.8 s budget.
**Do (preferred):** serve the built app in CI through a compressing server that mirrors production —
nginx + php-fpm (see `deploy/nginx/playndgain.com.conf` and the `gzip` settings there) — and keep
the gzip budgets. **Fallback:** keep `artisan serve` and restate the size assertions as raw
budgets (stylesheet ≤ 90 KB, script ≤ 55 KB) with a comment saying why; leave
`categories:accessibility` at 1 and `largest-contentful-paint` at 1800 ms only if the serving
change makes them attainable, otherwise document the measured value beside the assertion.

## Task 5 — Wall notice spacing on phones

**Files:** `resources/css/wall-ui.css` (the notice / panel rules used by
`resources/views/wall/notice.blade.php` and `consent.blade.php`)
**Problem:** on a 390 px wall the two notice buttons sit tight under the body text and the panel
hugs the top edge.
**Do:** ≈ 20 px between body and actions, ≈ 16 px top inset on phones; notices lead with the primary
button and a ghost, the consent screen keeps Accept / Decline at equal weight (deliberate — no
dark pattern).
**Check:** screenshot `/wall?appid=99999&userid=USER_1` (404 notice), `…&country=DE` (consent) and
the declined notice at 390; `tests/Feature/Wall` green.

## Task 6 — Decisions and assets (owner)

- **Filament state pills:** keep semantic sage / rose (`#6FD3A3` / `#D98B8B`, pinned in
  `resources/css/filament/theme.css`) or move badges to gold / mist per resource
  (`->color()` calls in `app/Filament/**`).
- **Social links:** set `PLAYNDGAIN_SOCIAL_X`, `_LINKEDIN`, `_DISCORD`, `_INSTAGRAM` — the footer
  renders only the configured ones (`resources/views/partials/site-footer.blade.php`).
- **Imagery:** `public/img/landing/{plate,plate-sm,plate-md,cta,cta-sm,cta-md}.webp` are generated
  placeholders (navy cloth ledger with gold rules). Replace when final art exists; keep the sizes
  (2400×720 / 1400×420 / 1200×360 and 2400×900 / 1600×600 / 1200×450). `public/img/og.png` is a
  typographic card (1200×630) — optional art.
- **Support address:** `PLAYNDGAIN_SUPPORT_EMAIL=support@playndgain.com` in the production `.env`
  (the CTA band prints it).
- **Fonts:** the site ships Newsreader's weight-axis cuts (58 KB upright, 65 KB italic). Restoring the
  optical-size cuts is a file swap in `public/fonts` + `resources/css/fonts.css` + the preloads in
  `resources/views/layouts/site.blade.php`, and costs ~150 KB on the hero's critical path.
- **Commit:** the rebrand is uncommitted on purpose so it can be reviewed as one diff
  (`git diff HEAD`). Commit only when the owner asks.

---

## Finish

```bash
composer ci
npm run build && php artisan responsecache:clear
php artisan docs:index          # only if any resources/docs/*.md changed
```

Then: full-page screenshots of `/` at 390 / 768 / 1024 / 1440 and the wall notice + consent at 390
(look at them, fix what is wrong); Lighthouse on `/` — accessibility must remain 100; a final
`grep -rIn -i advault . --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git` must
return nothing. Report what changed, the commands run with their results, and anything that still
needs an owner decision. Do not commit unless asked.
