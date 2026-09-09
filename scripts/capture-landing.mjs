#!/usr/bin/env node
/**
 * scripts/capture-landing.mjs — the landing page's product imagery, captured
 * from the real product running locally, and the social card.
 *
 *   node scripts/capture-landing.mjs                 # everything
 *   node scripts/capture-landing.mjs wall og         # a subset: wall | panel | og
 *
 * Needs Chrome, the site reachable at BASE_URL (default https://playndgain.test),
 * and the demo seeders run first — DemoWallSeeder for the wall, DemoActivitySeeder
 * for a dashboard with something on it. Environment: BASE_URL, APP_ID,
 * PUBLISHER_EMAIL, PUBLISHER_PASSWORD, CHROME_PATH.
 *
 * Writes public/img/landing/*.webp and public/img/og.png. Every file is a
 * screenshot of the product as it is; the only edit is removing the local
 * environment's pinned "test offer" row from the wall.
 *
 * Two things here are deliberate and were not before:
 *
 * Every wall capture names its theme. APP_ID used to default to an app whose
 * own theme is light, and the wall URLs carried no `theme` parameter, so the
 * files called `wall-dark*` were captures of the paper wall — wall-dark-1x and
 * wall-light-1x came out byte-identical, and the hero shipped a light phone
 * while design.md §6 said both themes were captured. `?theme=` is a per-load
 * override (WallContext::theme), so asking for it explicitly makes each file
 * the theme its name claims whichever app is behind APP_ID.
 *
 * The dashboard is captured scrolled. Unscrolled, the top of that page is a
 * greeting and a $0.00 daily total, which is a true thing about a demo account
 * and a poor argument for the product. Scrolling is not retouching: the
 * sidebar is sticky, so the frame keeps the whole panel and starts at the
 * balances — see dashboardPrepare().
 */
import puppeteer from 'puppeteer-core';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const out = resolve(root, 'public/img/landing');
mkdirSync(out, { recursive: true });

const BASE = (process.env.BASE_URL ?? 'https://playndgain.test').replace(/\/$/, '');
// DemoWallSeeder's app. It is the one with the seeded catalogue and the
// artwork fixtures on it, and its theme is this system's default (ink), so a
// dark capture of it is a capture of what an app gets out of the box.
const APP_ID = process.env.APP_ID ?? '10001';
const EMAIL = process.env.PUBLISHER_EMAIL ?? 'demo@playndgain.test';
const PASSWORD = process.env.PUBLISHER_PASSWORD ?? 'password';
const CHROME = process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const only = new Set(process.argv.slice(2));
const wants = (group) => only.size === 0 || only.has(group);

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: true,
  acceptInsecureCerts: true,
  args: ['--hide-scrollbars', '--ignore-certificate-errors', '--font-render-hinting=none'],
});

const pause = (ms) => new Promise((r) => setTimeout(r, ms));

/** Fonts loaded, images decoded, one frame of quiet. */
async function settle(page) {
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].filter((i) => !i.complete).map((i) => new Promise((r) => { i.onload = i.onerror = r; })));
  });
  await pause(500);
}

async function capture(name, { url, width, height, dpr = 2, quality = 82, prepare, page: existing, type = 'webp', dir = out }) {
  const page = existing ?? await browser.newPage();
  // `/img/offer/{id}` is served `immutable` for a year, correctly — an offer
  // that changes its art is a new sync. That also means a run started after
  // the artwork fixtures changed would happily re-screenshot the old tiles, so
  // this browser is told not to keep any of it.
  await page.setCacheEnabled(false);
  await page.setViewport({ width, height, deviceScaleFactor: dpr });
  if (url) await page.goto(url, { waitUntil: 'networkidle0', timeout: 90000 });
  if (prepare) await prepare(page);
  await settle(page);
  const file = resolve(dir, `${name}.${type}`);
  await page.screenshot({ path: file, type, ...(type === 'webp' ? { quality } : {}) });
  console.log(`${name}.${type}  ${width}×${height} @${dpr}x`);
  if (!existing) await page.close();
}

// The wall in test mode pins a "test offer" row so a publisher can fire a
// conversion end to end. It is a fixture of the local environment, not the
// product, so it leaves the picture. Nothing else is touched.
async function tidyWall(page) {
  await page.evaluate(() => {
    document.querySelector('.wall-loader')?.remove();
    for (const card of document.querySelectorAll('.wall-card')) {
      if (/test offer/i.test(card.textContent ?? '')) card.closest('li')?.remove();
    }
  });
}

// The theme is never left to the app's own setting: a file called wall-dark is
// the dark wall or it is misnamed. Everything else about the render is the
// app's — its colours, its radius, its currency.
const wallUrl = (theme) => `${BASE}/wall?appid=${APP_ID}&userid=LANDING_1&country=US&theme=${theme}`;

// Lower quality than the panels get, and it buys nothing back to raise it.
// The wall's cards carry photographic app artwork, which does not compress the
// way the panels' flat surfaces do: at the default 82 the hero file came out a
// third heavier than the gradient tiles it replaced, and the hero file is the
// page's largest contentful paint (lighthouserc.json fails the build over it).
// The phone renders that 780px capture at 260–330 CSS px, so it is downscaled
// by two to three before anyone sees it and the loss has nowhere to show —
// compared side by side at the size it is actually painted, 64 and 82 are the
// same picture. The card variant is lazy and below the fold, so it keeps its.
if (wants('wall')) {
  await capture('wall-dark', { url: wallUrl('dark'), width: 390, height: 780, dpr: 2, quality: 64, prepare: tidyWall });
  await capture('wall-dark-1x', { url: wallUrl('dark'), width: 390, height: 780, dpr: 1, quality: 72, prepare: tidyWall });
  await capture('wall-light', { url: wallUrl('light'), width: 390, height: 780, dpr: 2, quality: 64, prepare: tidyWall });
  await capture('wall-light-1x', { url: wallUrl('light'), width: 390, height: 780, dpr: 1, quality: 72, prepare: tidyWall });
  await capture('wall-dark-card', { url: wallUrl('dark'), width: 1100, height: 825, dpr: 1.5, quality: 78, prepare: tidyWall });
}

if (wants('panel')) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1.5 });
  await page.goto(`${BASE}/dashboard/login`, { waitUntil: 'networkidle0', timeout: 90000 });
  // The auth pages open behind the landing's intro curtain (auth-intro), which
  // swallows pointer events for up to 3.5 s; a click during it never submits.
  await page.waitForFunction(() => !document.documentElement.classList.contains('intro'), { timeout: 10000 });
  await pause(300);
  await page.type('#form\\.email', EMAIL);
  await page.type('#form\\.password', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 90000 }),
    page.click('button[type="submit"]'),
  ]);
  if (!/\/dashboard(?!\/login)/.test(page.url())) throw new Error(`login did not land on the dashboard: ${page.url()}`);

  // Charts and stat widgets arrive by Livewire after the page settles.
  const panelPrepare = async () => { await pause(1200); };

  // A dashboard whose focal point is a demo greeting and an empty daily total
  // argues nothing. Both of those are real, so neither is painted over and
  // neither is edited: the frame simply starts lower down the same page, at the
  // balances, and runs through the seven-day counters into the first chart. The
  // sidebar is sticky, so scrolling costs nothing — the panel is still whole.
  //
  // The widgets below the fold are lazy, and a lazy Livewire widget that has
  // never been in the viewport renders the word "Loading...". Waking them is
  // one trip to the bottom and back.
  const dashboardPrepare = async (page) => {
    await pause(1200);
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await pause(3000);
    await page.evaluate(() => {
      const heading = (section) => (section.querySelector('.fi-section-header-heading')?.textContent ?? '').trim();
      const balance = [...document.querySelectorAll('.fi-section')].find((section) => heading(section) === 'Balance');

      if (!balance) throw new Error('no Balance section on the dashboard to anchor the frame to');

      // Under the topbar, not behind it: the bar is sticky and would otherwise
      // sit on top of the heading the frame is supposed to open with.
      const bar = document.querySelector('.fi-topbar')?.getBoundingClientRect().height ?? 0;

      window.scrollTo(0, balance.getBoundingClientRect().top + window.scrollY - bar - 20);
    });
    await pause(700);
  };

  await capture('dashboard', { url: `${BASE}/dashboard`, width: 1440, height: 900, dpr: 1.5, quality: 80, page, prepare: dashboardPrepare });
  await capture('dashboard-1x', { width: 1440, height: 900, dpr: 1, quality: 78, page, prepare: dashboardPrepare });
  await capture('dashboard-card', { width: 1100, height: 825, dpr: 1.5, quality: 78, page, prepare: dashboardPrepare });

  const postbacks = await page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => a.href).find((h) => /postback/i.test(h)));
  if (!postbacks) throw new Error('no postback report link in the panel navigation');

  await capture('postbacks', { url: postbacks, width: 1440, height: 900, dpr: 1.5, quality: 80, page, prepare: panelPrepare });
  await capture('postbacks-1x', { width: 1440, height: 900, dpr: 1, quality: 78, page });
  await capture('postbacks-card', { width: 1100, height: 825, dpr: 1.5, quality: 78, page });
  await page.close();
}

if (wants('og')) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1200, height: 630, deviceScaleFactor: 1 });
  await page.setContent(ogCard(), { waitUntil: 'networkidle0' });
  await settle(page);
  await page.screenshot({ path: resolve(root, 'public/img/og.png'), type: 'png' });
  console.log('og.png  1200×630');
  await page.close();
}

await browser.close();

/** The social card: the hero's ground, the mark, the claim. */
function ogCard() {
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    @font-face { font-family: 'Sora'; src: url('${BASE}/fonts/sora-latin-wght-normal.woff2') format('woff2'); font-weight: 100 800; }
    html, body { margin: 0; width: 1200px; height: 630px; overflow: hidden; }
    body { position: relative; font-family: Sora, system-ui, sans-serif; color: #F4F5FA;
      background: radial-gradient(70% 60% at 50% 118%, rgba(255,176,32,.36), transparent 70%),
                  radial-gradient(40% 45% at 96% -12%, rgba(42,33,112,.75), transparent 70%), #0A0B14; }
    .orb { position: absolute; right: 60px; top: 60px; width: 460px; height: 460px; border-radius: 50%;
      background: radial-gradient(closest-side, rgba(255,176,32,.32), rgba(255,176,32,.08) 55%, transparent 72%); }
    .mark { position: absolute; left: 72px; top: 64px; display: flex; align-items: center; gap: 16px; font-weight: 700; font-size: 26px; letter-spacing: .02em; }
    h1 { position: absolute; left: 72px; top: 178px; margin: 0; max-width: 780px; font-size: 86px; line-height: 1.02; letter-spacing: -.03em; font-weight: 700; }
    p { position: absolute; left: 72px; top: 428px; margin: 0; max-width: 700px; font-size: 26px; line-height: 1.4; color: #A6ABBE; }
    .pill { position: absolute; right: 72px; bottom: 64px; display: inline-flex; align-items: center; gap: 10px; padding: 16px 26px; border-radius: 999px;
      background: linear-gradient(135deg, #FFC554, #F28C1A); color: #1A1000; font-weight: 600; font-size: 22px; box-shadow: 0 24px 64px -24px rgba(255,176,32,.6); }
  </style></head><body>
    <div class="orb"></div>
    <div class="mark">
      <svg width="52" height="52" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FFC554"/><stop offset="1" stop-color="#F28C1A"/></linearGradient></defs><rect width="64" height="64" rx="16" fill="url(#g)"/><rect x="15.5" y="13.5" width="11" height="38" rx="1.5" fill="#1A1000"/><path d="M21 19H33a11 11 0 0 1 0 22H21" fill="none" stroke="#1A1000" stroke-width="11"/></svg>
      PLAYNDGAIN
    </div>
    <h1>Turn playtime into payouts.</h1>
    <p>The rewarded offerwall for apps and games. Signed callbacks, an append-only ledger, terms stated once.</p>
    <div class="pill">Start earning →</div>
  </body></html>`;
}
