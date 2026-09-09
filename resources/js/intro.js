/* The page-open intro (partials/landing/intro, site-intro.css), shared by
   the landing (site.js) and the panels' auth pages (auth.js). It runs only
   when the page's gate script set html.intro — a first visit in this tab,
   motion allowed — and holds while the real critical assets arrive: the face
   and the page's own capture, each a step of the line under the mark. Then
   the mark flies onto the page's mark (one transform, from the two
   rectangles) as the curtain fades and the page enters behind it. Never past
   the cap: a slow network gets the page with its skeletons, not a longer
   wait. Resolves the moment the curtain starts to lift, so whatever waits on
   the intro arms then and not behind it.

   `marks` are selectors for the mark to land on, tried in order; the first
   one with a rendered box wins, so a page can name a desktop mark and a
   phone one. `image` is the capture to wait for; a lazy one is switched to
   eager, since the intro is waiting for it. */
export function runIntro({ marks = [], image = null } = {}) {
  const root = document.documentElement;
  // The block, not <html>: the layout marks the root with data-intro too,
  // and removing whichever matched first would take the whole page with it.
  const el = document.querySelector('.site-intro[data-intro]');
  if (! el || ! root.classList.contains('intro')) return Promise.resolve();

  return new Promise((resolve) => {
    const bar = el.querySelector('[data-intro-bar]');
    const mark = el.querySelector('[data-intro-mark]');
    const shot = typeof image === 'string' ? document.querySelector(image) : image;
    const started = performance.now();
    const MIN_HOLD = 1150;   // the mark's own entrance, start to finish
    const LETTERS = 1000;    // the wordmark, from the moment the face is in
    const CAP = 2600;        // the longest the assets are waited for
    let fontsAt = null;
    let progress = 0;
    let leaving = false;

    const step = (value) => {
      progress = Math.max(progress, value);
      if (bar) bar.style.transform = `scaleX(${progress})`;
    };

    const landingMark = () => {
      for (const selector of marks) {
        for (const candidate of document.querySelectorAll(selector)) {
          if (candidate.getBoundingClientRect().height > 0) return candidate;
        }
      }
      return null;
    };

    if (shot && shot.loading === 'lazy') shot.loading = 'eager';

    const face = (document.fonts ? document.fonts.load('700 1em "Sora Variable"') : Promise.resolve())
      .catch(() => {})
      .then(() => { fontsAt = performance.now(); root.classList.add('fonts-in'); step(0.45); });
    const capture = (shot && shot.decode ? shot.decode() : Promise.resolve())
      .catch(() => {})
      .then(() => step(0.85));

    const leave = () => {
      if (leaving) return;
      leaving = true;
      step(1);

      setTimeout(() => {
        const target = landingMark();

        try {
          if (mark && target) {
            const from = mark.getBoundingClientRect();
            const to = target.getBoundingClientRect();
            if (from.height > 0 && to.height > 0) {
              mark.style.transform = `translate(${to.left - from.left}px, ${to.top - from.top}px) scale(${to.height / from.height})`;
              target.style.visibility = 'hidden';
            }
          }
        } finally {
          root.classList.add('intro-out');
          root.classList.remove('intro');
          resolve();
        }

        let landed = false;
        const land = () => {
          if (landed) return;
          landed = true;
          if (target) target.style.visibility = '';
          el.classList.add('is-landed');
          setTimeout(() => { el.remove(); root.classList.remove('intro-out'); root.classList.add('intro-done'); }, 160);
        };
        mark?.addEventListener('transitionend', (event) => { if (event.propertyName === 'transform') land(); });
        setTimeout(land, 900);
      }, 200);
    };

    step(0.12);

    Promise.race([Promise.all([face, capture]), new Promise((r) => setTimeout(r, CAP))]).then(() => {
      const now = performance.now();
      const lettersBy = fontsAt === null ? now : fontsAt + LETTERS;
      setTimeout(leave, Math.max(started + MIN_HOLD - now, lettersBy - now, 0));
    });
    setTimeout(leave, CAP + 400);
  });
}
