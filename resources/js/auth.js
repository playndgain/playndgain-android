/* The panels' auth pages (login, register, password reset, verification —
   everything on Filament's simple layout): the page-open intro, the direction
   of a wizard step change, and a note that the page arrived through a
   cross-document view transition. Injected by resources/views/filament/
   auth-intro.blade.php; the motion itself is in filament/theme.css. Nothing
   here touches the dashboards, and nothing imports from site.js. */

import { runIntro } from './intro.js';

const root = document.documentElement;

/* A page that came in through a view transition (login → register, say) has
   already crossfaded; its entrance would be a second arrival. `pagereveal`
   fires before the first render, but a deferred module can miss it, so the
   pseudo-class is checked too — the transition is live for a few hundred
   milliseconds after. */
const arrivedByTransition = () => {
  try { return root.matches(':active-view-transition'); } catch (_) { return false; }
};
if (arrivedByTransition()) root.classList.add('vt');
window.addEventListener('pagereveal', (event) => { if (event.viewTransition) root.classList.add('vt'); });

/* Both of those can still miss a fast transition, so the leaving page also
   leaves a note the next page's gate script reads before its first paint. */
window.addEventListener('pageswap', (event) => {
  if (! event.viewTransition) return;
  try { sessionStorage.setItem('pg-vt', '1'); } catch (_) {}
});

runIntro({
  marks: ['.fi-auth-brand-mark .brand-mark', '.fi-simple-header .fi-logo .brand-mark'],
  image: '.fi-auth-phone img',
});

/* The register wizard swaps steps by toggling .fi-active. Which way the
   visitor went decides which side the next step slides in from: data-dir on
   the wizard is read by the stylesheet, and it is only set from the first
   change on, so the step already open when the page loads keeps still. */
for (const wizard of document.querySelectorAll('.fi-sc-wizard')) {
  const steps = [...wizard.querySelectorAll('.fi-sc-wizard-step')];
  let current = steps.findIndex((step) => step.classList.contains('fi-active'));

  const watch = new MutationObserver(() => {
    const next = steps.findIndex((step) => step.classList.contains('fi-active'));
    if (next === -1 || next === current) return;
    wizard.dataset.dir = next > current ? 'next' : 'back';
    current = next;
    // Restart the entrance even when the direction repeats.
    const active = steps[next];
    active.style.animation = 'none';
    void active.offsetWidth;
    active.style.animation = '';
  });

  steps.forEach((step) => watch.observe(step, { attributes: true, attributeFilter: ['class'] }));
}
