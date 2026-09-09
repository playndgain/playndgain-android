/* Landing + docs bundle: Alpine for the mobile menu and the one collapsible,
   plus the page's motion — entrances released by an IntersectionObserver,
   image skeletons, the header's scrolled state, a half-second count-up on
   real figures, and in-page hash scrolling. No animation library: everything
   here is a class toggle and the stylesheet does the moving. Every section is
   in the HTML before this file runs; without it, or under
   prefers-reduced-motion, the page is simply visible. The offerwall gets its
   own entry (wall.js) and must never import anything from here. */

import Alpine from 'alpinejs';
import { runIntro } from './intro.js';

const root = document.documentElement;
const still = window.matchMedia('(prefers-reduced-motion: reduce)');

/* Tell the layout failsafe that the bundle arrived — entrances are owned here. */
root.classList.add('site-ready');

/* The page-open intro (partials/landing/intro, intro.js): the line under the
   mark waits for the face and the hero capture, then the mark flies onto the
   header's. Resolves as the curtain starts to lift, so the scroll entrances
   arm then and not behind it. */
const introGone = runIntro({
  marks: ['.site-header-mark .brand-mark'],
  image: '.hero-enter img[fetchpriority="high"]',
});

/* Stagger indices: each child of a group tells the stylesheet its position. */
for (const group of document.querySelectorAll('[data-reveal-group], .seq')) {
  [...group.children].forEach((child, i) => child.style.setProperty('--i', String(i)));
}

/* Product shots: the frame shows a skeleton until its image has decoded, and
   keeps it if the image never arrives — never an empty black rectangle. */
const settle = (img) => img.closest('.shot')?.classList.add(img.naturalWidth > 0 ? 'is-loaded' : 'is-failed');

for (const img of document.querySelectorAll('.shot img')) {
  if (img.complete) settle(img);
  else {
    img.addEventListener('load', () => settle(img), { once: true });
    img.addEventListener('error', () => settle(img), { once: true });
  }
}

/* Lazy images start loading well before they scroll into view, so the
   skeleton is a moment rather than a wait. */
if ('IntersectionObserver' in window) {
  const warm = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (! entry.isIntersecting) continue;
      entry.target.loading = 'eager';
      warm.unobserve(entry.target);
    }
  }, { rootMargin: '1200px 0px' });

  document.querySelectorAll('img[loading="lazy"]').forEach((img) => warm.observe(img));
}

/* A real figure counts up for 600ms when its tile arrives. Only elements
   with data-metric — the server-rendered, defensible numbers — ever do;
   capability tiles have none, and reduced motion skips it entirely. */
function countUp(scope) {
  if (still.matches) return;

  for (const el of scope.querySelectorAll('[data-metric]')) {
    const target = Number(el.dataset.metric);
    if (! Number.isFinite(target)) continue;

    const decimals = (el.dataset.metric.split('.')[1] ?? '').length;
    const suffix = el.dataset.suffix ?? '';
    const format = (value) => value.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
    const started = performance.now();

    const tick = (now) => {
      const progress = Math.min(1, (now - started) / 600);
      const eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = format(target * eased);
      if (progress < 1) requestAnimationFrame(tick);
    };

    requestAnimationFrame(tick);
  }
}

/* Entrances: a block is released once, when a tenth of it is in view. */
const blocks = document.querySelectorAll('[data-reveal], [data-reveal-group]');
const releaseAll = () => blocks.forEach((block) => block.classList.add('is-in'));

if (still.matches || ! ('IntersectionObserver' in window)) {
  releaseAll();
} else {
  const reveal = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (! entry.isIntersecting) continue;
      entry.target.classList.add('is-in');
      countUp(entry.target);
      reveal.unobserve(entry.target);
    }
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.1 });

  introGone.then(() => blocks.forEach((block) => reveal.observe(block)));
  still.addEventListener('change', () => { if (still.matches) { releaseAll(); reveal.disconnect(); } });
}

/* The header firms up once the page has moved. */
const nav = document.querySelector('[data-nav]');

if (nav) {
  let scrolled = null;
  const update = () => {
    const next = window.scrollY > 8;
    if (next !== scrolled) { scrolled = next; nav.classList.toggle('is-scrolled', next); }
  };
  update();
  window.addEventListener('scroll', update, { passive: true });
}

/* In-page hash links: on the home path, ease to the target instead of a
   hard jump. Absolute `/#section` hrefs from the shared header still work
   from docs and legal pages as normal navigations. */
let scrollFrame = 0;

function easeOutCubic(t) {
  return 1 - Math.pow(1 - t, 3);
}

function animateScrollTo(y) {
  if (still.matches) {
    window.scrollTo(0, y);
    return;
  }

  const start = window.scrollY;
  const delta = y - start;
  if (Math.abs(delta) < 2) return;

  const duration = Math.min(1100, Math.max(420, Math.abs(delta) * 0.55));
  const started = performance.now();
  cancelAnimationFrame(scrollFrame);

  const tick = (now) => {
    const progress = Math.min(1, (now - started) / duration);
    window.scrollTo(0, start + delta * easeOutCubic(progress));
    if (progress < 1) scrollFrame = requestAnimationFrame(tick);
  };

  scrollFrame = requestAnimationFrame(tick);
}

function scrollToHash(hash, updateHistory, animate = true) {
  if (! hash || hash === '#') return false;

  const id = decodeURIComponent(hash.slice(1));
  const target = document.getElementById(id);
  if (! target) return false;

  const margin = parseFloat(getComputedStyle(target).scrollMarginTop) || 0;
  const top = target.getBoundingClientRect().top + window.scrollY - margin;

  if (animate) animateScrollTo(top);
  else window.scrollTo(0, top);

  if (updateHistory && history.pushState) history.pushState(null, '', `#${id}`);
  return true;
}

/* Arriving on a fragment is not a scroll anybody asked for: the browser has
   already put the page where it belongs, and easing there from the top means
   watching the whole page go by first. Clicks below still ease. */
if (location.hash) scrollToHash(location.hash, false, false);

document.addEventListener('click', (event) => {
  const link = event.target.closest('a[href]');
  if (! link || event.defaultPrevented || event.button !== 0) return;
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

  let url;
  try { url = new URL(link.href, location.href); } catch (_) { return; }
  if (url.origin !== location.origin || ! url.hash) return;

  const here = location.pathname.replace(/\/$/, '') || '/';
  const there = url.pathname.replace(/\/$/, '') || '/';
  if (here !== '/' || there !== '/') return;

  if (! scrollToHash(url.hash, true)) return;
  event.preventDefault();
});

/* Highlight the hash nav item whose section is in view. Docs links (no hash
   target on this page) are left alone. */
const navLinks = [...document.querySelectorAll('[data-nav-link]')];
const sectionLinks = navLinks
  .map((link) => {
    try {
      const url = new URL(link.href, location.href);
      if (! url.hash) return null;
      const el = document.getElementById(decodeURIComponent(url.hash.slice(1)));
      return el ? { link, el } : null;
    } catch (_) {
      return null;
    }
  })
  .filter(Boolean);

if (sectionLinks.length && 'IntersectionObserver' in window) {
  const visible = new Map();

  const sync = () => {
    let best = null;
    for (const [el, ratio] of visible) {
      if (! best || ratio > best.ratio) best = { el, ratio };
    }
    const active = best?.el ?? null;
    for (const { link, el } of sectionLinks) {
      link.classList.toggle('is-active', el === active);
    }
  };

  const spy = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) visible.set(entry.target, entry.intersectionRatio);
      else visible.delete(entry.target);
    }
    sync();
  }, { rootMargin: '-20% 0px -55% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] });

  sectionLinks.forEach(({ el }) => spy.observe(el));
}

/* ── Toast ────────────────────────────────────────────────────────────────
   One ink card in the layout's live region. Built here rather than rendered
   because it answers something that happened after the page was sent; the
   region itself is in the markup so a screen reader is already listening when
   the card arrives. The entrance is two classes — app.css moves it, and the
   reduce block there collapses the move to nothing. */
const CHECK = '<path d="M5 12.5l4.5 4.5L19 7"/>';
const CROSS = '<path d="M6 6l12 12M18 6L6 18"/>';

function icon(paths, cls) {
  return `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
}

function toast(title, note, { warn = false, life = 6000 } = {}) {
  const wrap = document.querySelector('[data-toasts]');
  if (! wrap) return;

  const card = document.createElement('div');
  card.className = warn ? 'toast toast-warn' : 'toast';
  card.innerHTML =
    icon(warn ? CROSS : CHECK, 'toast-icon') +
    '<div class="toast-body"><p class="toast-title"></p>' +
    (note ? '<p class="toast-note"></p>' : '') +
    '</div>' +
    `<button type="button" class="toast-close" aria-label="Dismiss">${icon(CROSS, '')}</button>`;

  // textContent, not the template: the only untrusted string here is the
  // validator's message, and it is not going into markup.
  card.querySelector('.toast-title').textContent = title;
  if (note) card.querySelector('.toast-note').textContent = note;

  let timer = 0;
  const close = () => {
    clearTimeout(timer);
    card.classList.remove('is-in');
    card.classList.add('is-out');
    // transitionend does not fire when the reduce block has already collapsed
    // the duration, so the removal is on a timer either way.
    setTimeout(() => card.remove(), 260);
  };

  card.querySelector('.toast-close').addEventListener('click', close);
  wrap.append(card);
  requestAnimationFrame(() => card.classList.add('is-in'));

  timer = setTimeout(close, life);

  // A toast that vanishes while it is being read is a toast nobody read.
  card.addEventListener('pointerenter', () => clearTimeout(timer));
  card.addEventListener('pointerleave', () => { timer = setTimeout(close, 2000); });
}

/* ── The footer's mailing list ────────────────────────────────────────────
   With the bundle in, the press never leaves the page: no navigation, so
   nothing scrolls, the button settles into "Subscribed", and the answer
   arrives as a toast. With the bundle blocked this file is not here and the
   same form posts, redirects and renders the same words in place. */
const list = document.querySelector('[data-newsletter]');

if (list) {
  const button = list.querySelector('[data-newsletter-button]');
  const field = list.querySelector('input[type="email"]');
  const inline = document.querySelector('[data-newsletter-inline]');
  const idle = button.innerHTML;
  let settledAt = null;

  const settle = () => {
    settledAt = field.value;
    button.disabled = true;
    button.classList.remove('btn-ink');
    button.classList.add('btn-ghost-paper');
    button.innerHTML = `${icon(CHECK, 'size-4 text-amber-600')}Subscribed`;
  };

  // A dead form is worse than a settled one: a second address re-arms it.
  const rearm = () => {
    if (settledAt === null || field.value === settledAt) return;
    settledAt = null;
    button.disabled = false;
    button.classList.add('btn-ink');
    button.classList.remove('btn-ghost-paper');
    button.innerHTML = idle;
  };

  field.addEventListener('input', rearm);

  list.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (button.disabled) return;

    // The server-rendered line belongs to the no-JS path. Once this arm is
    // answering, two answers on screen is one too many.
    inline?.remove();
    field.removeAttribute('aria-invalid');
    button.disabled = true;

    try {
      const response = await fetch(list.action, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(list),
      });

      if (response.ok) {
        const body = await response.json();
        settle();
        toast(body.message ?? 'You are on the list.', 'Release notes and new demand partners. Roughly monthly.');
        return;
      }

      button.disabled = false;

      if (response.status === 422) {
        const body = await response.json();
        const message = body.errors?.email?.[0] ?? 'That address did not look right.';
        field.setAttribute('aria-invalid', 'true');
        field.focus();
        toast(message, null, { warn: true });
        return;
      }

      if (response.status === 429) {
        toast('Too many tries.', 'Give it a minute and press again.', { warn: true });
        return;
      }

      throw new Error(`HTTP ${response.status}`);
    } catch (_) {
      // The network, an expired session, anything unexpected: hand it back to
      // the browser, which will post the form for real and let the server say
      // what happened on a page it renders itself.
      button.disabled = false;
      list.submit();
    }
  });
}

window.Alpine = Alpine;
Alpine.start();
