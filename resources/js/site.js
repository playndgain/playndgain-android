/* Landing + docs bundle: Alpine + GSAP. The offerwall gets wall.js — Alpine
   only — and must never import anything from this file. */

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import intersect from '@alpinejs/intersect';
import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

window.Alpine = Alpine;
Alpine.plugin(collapse);
Alpine.plugin(intersect);
Alpine.start();

(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Mark JS-ready only when we intend to animate. Reveals stay visible otherwise.
  if (!reduce) document.documentElement.classList.add('js-ready');

  // Counters are server-rendered at their final value, so reduced motion — and a
  // bundle that never loads — leaves the metrics band reading correctly.
  if (reduce) return;

  gsap.registerPlugin(ScrollTrigger);

  // ── Hero: the trace assembles in the order the money actually moves ──
  gsap.set('#device', { opacity: 0, y: 24, scale: .97 });
  gsap.set('.chip',   { opacity: 0, y: 14 });

  gsap.timeline({ defaults: { ease: 'expo.out' } })
    .to('.reveal', { opacity: 1, y: 0, duration: .5, stagger: .07 }, 0)
    .to('#device', { opacity: 1, y: 0, scale: 1, duration: .9 }, .15)
    .to('.chip',   { opacity: 1, y: 0, duration: .55, stagger: .11 }, .5);

  // ── The phone earns while you read. One event every ~7s, not a light show. ──
  var bal = document.getElementById('coinBal'),
      row = document.getElementById('liveRow'),
      win = document.getElementById('floatWin');

  if (bal && row && win) {
    var coins = 2430;

    var earn = gsap.timeline({ repeat: -1, repeatDelay: 5.5, paused: true })
      .to(row,  { boxShadow: '0 0 0 1px rgba(245,165,36,.85)', duration: .25, ease: 'power2.out' })
      .fromTo(win, { opacity: 0, y: 4 }, { opacity: 1, y: -12, duration: .5, ease: 'expo.out' }, '<')
      .add(function () {
        var to = { v: coins };
        coins += 640;
        gsap.to(to, {
          v: coins, duration: .8, ease: 'expo.out',
          onUpdate: function () { bal.textContent = Math.round(to.v).toLocaleString('en-US'); }
        });
      }, '<0.1')
      .to(win, { opacity: 0, y: -20, duration: .35 }, '>-0.05')
      .to(row, { boxShadow: '0 0 0 0px rgba(245,165,36,0)', duration: .4 }, '<');

    // Only run while the hero is actually on screen — no work in a background tab
    // and none once the visitor has scrolled past it.
    ScrollTrigger.create({
      trigger: '#traceWrap', start: 'top bottom', end: 'bottom top',
      onToggle: function (self) { self.isActive ? earn.play() : earn.pause(); }
    });
  }

  // ── Section reveals ──
  gsap.utils.toArray('.reveal').forEach(function (el) {
    if (el.closest('section')?.matches(':first-of-type')) return;
    ScrollTrigger.create({
      trigger: el, start: 'top 88%', once: true,
      onEnter: function () { el.classList.add('in'); }
    });
  });

  // ── Feature grid: wave stagger ──
  ScrollTrigger.create({
    trigger: '.feat', start: 'top 85%', once: true,
    onEnter: function () {
      gsap.from('.feat', { opacity: 0, y: 18, duration: .45, stagger: { each: .06, grid: 'auto' }, ease: 'back.out(1.3)' });
    }
  });

  // ── Counters. The figure is already in the HTML; we knock it back to zero and
  //    play it in. The page never ships a literal 0 a visitor could read. ──
  document.querySelectorAll('[data-count]').forEach(function (el) {
    var target = Number(el.dataset.count), obj = { v: 0 };
    el.textContent = '0';
    ScrollTrigger.create({
      trigger: el, start: 'top 92%', once: true,
      onEnter: function () {
        gsap.to(obj, {
          v: target, duration: 1.4, ease: 'expo.out',
          onUpdate: function () { el.textContent = Math.round(obj.v).toLocaleString('en-US'); }
        });
      }
    });
  });

  // ── Rail hides over light sections: it is dark-on-dark by design and
  //    would otherwise collide with content on the chalk and amber bands.
  var rail = document.getElementById('rail');
  gsap.utils.toArray('[data-light]').forEach(function (sec) {
    ScrollTrigger.create({
      trigger: sec, start: 'top 60%', end: 'bottom 40%',
      onToggle: function (self) { if (rail) rail.style.opacity = self.isActive ? '0' : '1'; }
    });
  });

  // ── Trace rail: playhead scrubs the whole document ──
  var dots = gsap.utils.toArray('.rail-dot');
  if (dots.length) {
    ScrollTrigger.create({
      trigger: document.body, start: 'top top', end: 'bottom bottom', scrub: .6,
      onUpdate: function (self) {
        var fill = document.getElementById('railFill');
        var track = dots[dots.length - 1].offsetTop + 4;
        if (fill) fill.style.height = (track * self.progress) + 'px';

        dots.forEach(function (d, i) {
          var active = self.progress >= (i / dots.length);
          d.querySelector('span:first-child').style.background = active ? '#F5A524' : '#363D47';
          d.querySelector('span:last-child').style.opacity = active ? '1' : '0';
        });
      }
    });
  }
})();
