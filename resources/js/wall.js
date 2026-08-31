/* Offerwall bundle. Alpine and Motion's mini animate — no GSAP, ever, and no
   Alpine plugins. This is a list of cards on a mid-range Android over 3G
   (docs/09 §6), and the budget is the reason the wall has its own entry
   instead of sharing site.js. tests/Feature/Landing/BundleTest.php asserts the
   emitted chunk graph; 34 KB gzipped of animation engine is the whole budget
   twice over, which is why the animation here is Motion's WAAPI-only build at
   roughly a tenth of that.

   Every behaviour below is an enhancement over markup that already works:

     - the filter narrows a list the server already rendered;
     - the reveal window hides cards that are already in the DOM, so with JS
       off nothing is hidden and the whole wall is there and the Show-more
       button that would widen it is not;
     - a card is a real link to a real page, and `open()` only intercepts a
       plain left click — the detail view it fetches is the same URL the link
       points at, rendered as a fragment.

   With JS off the wall renders, every offer opens, and every offer's terms are
   one navigation away. That is the invariant this file may not break.

   All of it lives here rather than in `x-data` attributes on the page: the
   markup stays readable, and the wall's own test asserts the rendered HTML
   carries no `$`, which an Alpine magic in an attribute would violate. */

import '../css/wall.css';

import Alpine from 'alpinejs';
import { animate } from 'motion/mini';

/** Cards revealed per scroll step. One screenful on a phone, two on a laptop. */
const PAGE = 24;

const still = window.matchMedia('(prefers-reduced-motion: reduce)');

/** Motion, or nothing at all when the reader asked for less of it. */
function move(target, keyframes, options = {}) {
    if (still.matches || !target || (target.length === 0 && !target.nodeType)) {
        return null;
    }

    return animate(target, keyframes, { duration: 0.3, ease: [0.22, 1, 0.36, 1], ...options });
}

Alpine.data('wall', (list) => ({
    q: '',
    cat: 'all',
    device: 'all',
    list,

    /** Position of each card among the currently visible ones; -1 = filtered out. */
    rank: [],
    hits: list.length,

    /** How far down the filtered list the reveal window currently reaches. */
    shown: PAGE,

    init() {
        this.refresh();

        // One recompute per filter change, not one per card per render: at 100
        // cards the naive version is 10,000 comparisons for every keystroke.
        this.$watch('q', () => this.refresh(true));
        this.$watch('cat', () => this.refresh(true));
        this.$watch('device', () => this.refresh(true));

        this.$nextTick(() => {
            this.reveal();
            this.syncRail();
        });
    },

    /** Hide the rail's next control when every featured card already fits. */
    syncRail() {
        const track = document.querySelector('.wall-rail__track');
        const next = document.querySelector('.wall-rail__next');

        if (! track || ! next) {
            return;
        }

        next.hidden = track.scrollWidth <= track.clientWidth + 2;
    },

    refresh(reset = false) {
        const tokens = this.q.trim().toLowerCase().split(/\s+/).filter(Boolean);
        let n = 0;

        this.rank = this.list.map((offer) => {
            const devices = offer.d ?? [];
            const hay = `${offer.n} ${offer.i ?? ''} ${(offer.c ?? []).join(' ')} ${devices.join(' ')}`.toLowerCase();

            return (this.cat === 'all' || offer.c.includes(this.cat))
                && (this.device === 'all' || devices.length === 0 || devices.includes(this.device))
                && tokens.every((token) => hay.includes(token))
                    ? n++
                    : -1;
        });

        this.hits = n;

        if (reset) {
            // A new filter starts at the top of its own list. Keeping the old
            // window would leave a search for one word showing a blank grid
            // until the user scrolled back up.
            this.shown = PAGE;
            this.$nextTick(() => this.reveal());
        }
    },

    more() {
        if (this.shown >= this.hits) {
            return;
        }

        this.shown += PAGE;
        this.$nextTick(() => this.reveal());
    },

    /**
     * Fade the cards that just entered the window, staggered down the list.
     *
     * `data-in` marks the ones already animated: a card is revealed once, and
     * re-animating the whole grid on every scroll step is both wasted work and
     * a flicker on the cards the user is currently reading.
     */
    reveal() {
        const fresh = Array.from(document.querySelectorAll('.wall-grid > li:not([data-in])'))
            .filter((el) => el.offsetParent !== null)
            .slice(0, PAGE);

        fresh.forEach((el, i) => {
            el.dataset.in = '1';
            move(el, { opacity: [0, 1], transform: ['translateY(10px)', 'none'] }, { delay: i * 0.035 });
        });
    },

    /**
     * Submit the sort form as soon as the reader picks an order, so the extra
     * button is a no-JS fallback rather than a second thing to press.
     *
     * A method here rather than `@change="$el.form.submit()"` in the markup:
     * the wall's own test forbids a `$` in the rendered HTML, because one is
     * indistinguishable from a USD payout leaking onto a currency-only surface.
     *
     * `requestSubmit`, not `submit` — it runs the form's own validation and
     * fires the submit event, which `submit()` skips.
     */
    sortNow(event) {
        event.target.form.requestSubmit();
    },

    /**
     * Advance the featured strip by one card. The track is still a native
     * scroll region — this only saves a swipe. Hidden with `x-cloak` when
     * Alpine never boots, so a no-JS reader is not left with a dead button.
     */
    railNext(event) {
        const track = event.currentTarget.closest('.wall-rail__frame')?.querySelector('.wall-rail__track');
        const card = track?.querySelector('li');

        if (! track || ! card) {
            return;
        }

        const step = card.getBoundingClientRect().width + 10;
        const rtl = document.documentElement.dir === 'rtl';

        track.scrollBy({ left: rtl ? -step : step, behavior: still.matches ? 'auto' : 'smooth' });
        this.syncRail();
    },

    /**
     * The card's own href, in a dialog.
     *
     * Modified clicks and middle clicks are left alone — a user who ctrl-clicks
     * an offer wants a tab, and swallowing that is the kind of hijack that makes
     * a page feel broken. A failed fetch falls through to the same navigation,
     * so the worst case is the no-JS behaviour rather than a dead card.
     */
    async open(event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
        }

        event.preventDefault();

        const link = event.currentTarget;
        const dialog = document.getElementById('offer-dialog');
        const body = document.getElementById('offer-dialog-body');
        const skeleton = document.getElementById('offer-dialog-skeleton');

        body.replaceChildren(skeleton ? skeleton.content.cloneNode(true) : '');
        dialog.setAttribute('aria-busy', 'true');
        present(dialog);

        try {
            const response = await fetch(`${link.href}&fragment=1`, { headers: { Accept: 'text/html' } });

            if (!response.ok) {
                throw new Error(response.status);
            }

            body.innerHTML = await response.text();
        } catch {
            dismiss(dialog);
            window.location.href = link.href;

            return;
        }

        dialog.setAttribute('aria-busy', 'false');
        body.scrollTop = 0;
        move(body.firstElementChild ?? body, { opacity: [0, 1], transform: ['translateY(10px)', 'none'] });
    },
}));

/** Open a native dialog on the next frame so the enter transition can run. */
function present(dialog) {
    dialog._leave = null;
    dialog.classList.remove('is-out');

    if (! dialog.open) {
        dialog.showModal();
    }

    if (still.matches) {
        dialog.classList.add('is-in');
        return;
    }

    requestAnimationFrame(() => dialog.classList.add('is-in'));
}

/** Close after the leave transition. Instant when the reader asked for less. */
function dismiss(dialog) {
    if (! dialog.open || dialog.classList.contains('is-out')) {
        return;
    }

    const token = {};
    dialog._leave = token;

    const finish = () => {
        if (dialog._leave !== token) {
            return;
        }

        dialog._leave = null;

        if (dialog.open) {
            dialog.close();
        }

        dialog.classList.remove('is-in', 'is-out');
        dialog.setAttribute('aria-busy', 'false');
    };

    if (still.matches) {
        finish();
        return;
    }

    dialog.classList.remove('is-in');
    dialog.classList.add('is-out');
    dialog.addEventListener('transitionend', (event) => {
        if (event.target === dialog) {
            finish();
        }
    }, { once: true });
    window.setTimeout(finish, 320);
}

// Dialogs are shells the whole page shares, so their wiring belongs here and
// not on a card or a menu item. Backdrop clicks close them; Esc is intercepted
// so the leave transition can run first. The menu's trigger is plain markup
// with a data attribute rather than an Alpine directive: the rendered HTML
// carries no `$`, which the wall's own test forbids, and the trigger works on
// pages with no wall component.
document.addEventListener('alpine:init', () => {
    for (const dialog of document.querySelectorAll('dialog')) {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog || event.target.closest('[data-close]')) {
                if (dialog.classList.contains('offer-dialog')) {
                    dismiss(dialog);
                } else {
                    dialog.close();
                }
            }
        });

        dialog.addEventListener('cancel', (event) => {
            if (dialog.classList.contains('offer-dialog')) {
                event.preventDefault();
                dismiss(dialog);
            }
        });
    }

    const menu = document.getElementById('wall-menu');

    if (menu) {
        for (const trigger of document.querySelectorAll('[data-menu]')) {
            trigger.addEventListener('click', () => {
                // showModal throws on an already-open dialog; a second tap is
                // a no-op rather than an exception.
                if (!menu.open) {
                    menu.showModal();
                }
            });
        }
    }
});

window.Alpine = Alpine;
Alpine.start();
