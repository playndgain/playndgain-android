{{-- Prose styling and the docs Alpine components.

     Inline rather than in resources/css/app.css and resources/js/site.js
     because the HTML the Markdown parser emits has no class attributes to
     hang Tailwind utilities on — `.doc-prose h2` is the only way to reach it —
     and both of those files belong to the landing site.

     Literal hex, matching app.css, rather than var(--color-*): Tailwind v4
     only emits the theme variables it saw used, and a colour referenced from
     a Blade <style> block is not something it sees.

     The <script> is in `head`, which the layout stacks *before* @vite, so it
     registers on `alpine:init` before the deferred bundle calls Alpine.start(). --}}

@push('head')
<style>
  /* ── Prose ───────────────────────────────────────────────────────────── */
  .doc-prose { color: #A2ABB8; font-size: 15.5px; line-height: 1.75; }
  .doc-prose > * + * { margin-top: 1.1rem; }

  .doc-prose h2, .doc-prose h3 {
    font-family: "Archivo", sans-serif; font-variation-settings: "wdth" 112;
    letter-spacing: -0.018em; color: #E9ECF1; scroll-margin-top: 96px;
  }
  .doc-prose h2 { margin-top: 2.9rem; font-size: 1.4rem; font-weight: 700; }
  .doc-prose h3 { margin-top: 2.1rem; font-size: 1.08rem; font-weight: 600; }

  .doc-anchor {
    margin-left: .45em; color: #363D47; text-decoration: none;
    font-weight: 400; opacity: 0; transition: opacity .15s;
  }
  h2:hover > .doc-anchor, h3:hover > .doc-anchor,
  .doc-anchor:focus-visible { opacity: 1; }

  .doc-prose strong { color: #E9ECF1; font-weight: 600; }
  .doc-prose em { color: #A2ABB8; }
  .doc-prose a { color: #F5A524; text-decoration: underline; text-underline-offset: 3px;
                 text-decoration-color: rgba(245,165,36,.35); }
  .doc-prose a:hover { color: #FFBE4D; text-decoration-color: #FFBE4D; }
  .doc-prose a.doc-anchor { text-decoration: none; }

  .doc-prose ul, .doc-prose ol { padding-left: 1.15rem; }
  .doc-prose ul { list-style: disc; }
  .doc-prose ol { list-style: decimal; }
  .doc-prose li { margin-top: .4rem; }
  .doc-prose li::marker { color: #6E7784; }

  .doc-prose hr { border: 0; height: 1px; margin: 2.4rem 0;
                  background: linear-gradient(90deg, #262B33, transparent); }

  .doc-prose blockquote {
    border-left: 2px solid #F5A524; background: #14171B;
    border-radius: 0 10px 10px 0; padding: 1rem 1.25rem;
  }
  .doc-prose blockquote > * + * { margin-top: .8rem; }

  /* Inline code. Not the obsidian slab — that is for blocks. */
  .doc-prose :not(pre) > code {
    font-family: "Geist Mono", monospace; font-size: .87em;
    background: #1A1E24; border: 1px solid #262B33; border-radius: 5px;
    padding: .12em .4em; color: #E9ECF1; white-space: nowrap;
  }

  /* ── Tables ──────────────────────────────────────────────────────────── */
  .doc-table-wrap { overflow-x: auto; }
  .doc-prose table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  .doc-prose th, .doc-prose td {
    border-bottom: 1px solid #262B33; padding: .6rem .8rem;
    text-align: left; vertical-align: top;
  }
  .doc-prose th { color: #E9ECF1; font-weight: 600; font-size: 12px;
                  text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
  .doc-prose tbody tr:hover { background: rgba(20,23,27,.6); }

  /* ── Code blocks. Obsidian in every context — code reads better dark. ── */
  .doc-code { position: relative; margin-top: 1.1rem; }
  .doc-code pre {
    overflow-x: auto; background: #08090A; border: 1px solid #262B33;
    border-radius: 12px; padding: 1rem 1.15rem; margin: 0;
    font-family: "Geist Mono", monospace; font-size: 12.5px; line-height: 1.7;
    color: #C7CEDA; tab-size: 4;
  }
  .doc-copy {
    position: absolute; top: 8px; right: 8px; z-index: 1;
    font-family: "Geist Mono", monospace; font-size: 11px;
    color: #6E7784; background: #14171B; border: 1px solid #262B33;
    border-radius: 6px; padding: 3px 9px; cursor: pointer;
    opacity: 0; transition: opacity .15s, color .15s, border-color .15s;
  }
  .doc-code:hover .doc-copy, .doc-copy:focus-visible { opacity: 1; }
  .doc-copy:hover { color: #E9ECF1; border-color: #363D47; }

  /* tempest/highlight tokens. Three colours: amber is syntax, green is a
     literal value, chalk is a name. Go and Ruby have no grammar in the
     highlighter and fall through to the base colour, which is why the base
     colour is legible rather than a placeholder grey. */
  .hl-keyword, .hl-type, .hl-number, .hl-attribute { color: #FFBE4D; }
  .hl-value, .hl-literal { color: #2FBF71; }
  .hl-variable, .hl-injection { color: #E9ECF1; }
  .hl-property, .hl-operator, .hl-generic { color: #A2ABB8; }
  .hl-comment { color: #8A94A2; font-style: italic; }

  /* ── Language tabs ───────────────────────────────────────────────────── */
  .doc-tabs { margin-top: 1.1rem; }
  .doc-tabs-bar { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: -.4rem; }
  .doc-tabs-bar button {
    font-family: "Geist Mono", monospace; font-size: 12px; color: #6E7784;
    background: transparent; border: 1px solid transparent; border-radius: 7px;
    padding: 4px 10px; cursor: pointer; transition: color .15s, background-color .15s;
  }
  .doc-tabs-bar button:hover { color: #A2ABB8; }
  .doc-tabs-bar button.is-on { color: #E9ECF1; background: #1A1E24; border-color: #262B33; }

  /* ── Postback URL builder ────────────────────────────────────────────── */
  .doc-builder {
    margin-top: 1.1rem; border: 1px solid #262B33; border-radius: 12px;
    background: #14171B; padding: 1.1rem; position: relative;
  }
  .doc-builder-label { font-family: "Geist Mono", monospace; font-size: 11px;
    text-transform: uppercase; letter-spacing: .06em; color: #6E7784; margin: 0 0 .45rem; }
  .doc-builder-label + * { margin-top: 0; }
  .doc-builder-input {
    width: 100%; background: #08090A; border: 1px solid #262B33; border-radius: 8px;
    padding: .5rem .7rem; color: #E9ECF1; font-family: "Geist Mono", monospace; font-size: 12.5px;
  }
  .doc-builder-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: .3rem .8rem; margin: 0 0 1rem;
  }
  .doc-builder-grid label { display: flex; align-items: center; gap: .5rem;
    font-size: 13px; color: #A2ABB8; cursor: pointer; }
  .doc-builder-grid input { accent-color: #F5A524; }
  .doc-builder-grid code { font-family: "Geist Mono", monospace; font-size: 12px; }
  .doc-builder-out {
    display: block; word-break: break-all; background: #08090A;
    border: 1px solid #262B33; border-radius: 8px; padding: .7rem .8rem;
    font-family: "Geist Mono", monospace; font-size: 12px; line-height: 1.65; color: #2FBF71;
  }
  .doc-builder-copy {
    margin-top: .7rem; font-family: "Geist Mono", monospace; font-size: 11px;
    color: #6E7784; background: #1A1E24; border: 1px solid #262B33;
    border-radius: 6px; padding: 4px 10px; cursor: pointer;
  }
  .doc-builder-copy:hover { color: #E9ECF1; border-color: #363D47; }

  /* ── Table of contents ───────────────────────────────────────────────── */
  .doc-toc a { display: block; padding: 3px 0 3px 11px; margin-left: -1px;
    border-left: 1px solid transparent; color: #6E7784; font-size: 12.5px; line-height: 1.5;
    transition: color .15s, border-color .15s; }
  .doc-toc a:hover { color: #A2ABB8; }
  .doc-toc a.is-on { color: #F5A524; border-left-color: #F5A524; }
  .doc-toc .lvl-3 { padding-left: 22px; }

  /* Native disclosure, so the mobile nav and the mobile contents both work
     with JavaScript off. The landing site's own summary reset lives in
     app.css and applies here too. */
  .doc-disclosure summary::after {
    content: ""; width: 7px; height: 7px; margin-left: auto;
    border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor;
    transform: rotate(45deg) translateY(-2px); transition: transform .2s;
  }
  .doc-disclosure[open] summary::after { transform: rotate(-135deg) translateY(-2px); }
</style>

<script>
  document.addEventListener('alpine:init', function () {
    var flash = function (component) {
      component.label = 'Copied';
      setTimeout(function () { component.label = 'Copy'; }, 1500);
    };

    Alpine.data('docsCopy', function () {
      return {
        label: 'Copy',
        copy: function () {
          var pre = this.$el.querySelector('pre');
          navigator.clipboard.writeText(pre.innerText).then(flash.bind(null, this));
        },
      };
    });

    // The order the macros are documented in, so the built URL reads the same
    // way as the table above it however the reader ticked the boxes.
    var MACROS = ['user_id', 'trans_id', 'offer_id', 'offer_name', 'payout_usd',
      'currency_amount', 'app_id', 'country', 'ip', 'status', 'timestamp', 'signature'];

    Alpine.data('docsUrlBuilder', function () {
      return {
        base: 'https://yoursite.com/advault/callback',
        picked: ['user_id', 'trans_id', 'payout_usd', 'currency_amount', 'status', 'signature'],
        label: 'Copy',
        get url() {
          var self = this;
          var query = MACROS.filter(function (m) { return self.picked.indexOf(m) !== -1; })
            .map(function (m) { return m + '={' + m + '}'; })
            .join('&');

          return query ? this.base + '?' + query : this.base;
        },
        copy: function () {
          navigator.clipboard.writeText(this.url).then(flash.bind(null, this));
        },
      };
    });

    Alpine.data('docsToc', function () {
      return {
        active: '',
        init: function () {
          var self = this;
          var headings = document.querySelectorAll('.doc-prose h2[id], .doc-prose h3[id]');

          if (! headings.length || ! window.IntersectionObserver) return;

          // The bottom inset means a heading counts as active from the moment
          // it clears the header until the next one does — without it the last
          // heading on a page can never win, because the page stops scrolling.
          var observer = new IntersectionObserver(function (entries) {
            var visible = entries.filter(function (e) { return e.isIntersecting; });
            if (visible.length) self.active = visible[0].target.id;
          }, { rootMargin: '-96px 0px -68% 0px' });

          headings.forEach(function (h) { observer.observe(h); });
        },
      };
    });

    // Six lines, and it fixes the only thing a full page load actually loses.
    Alpine.data('docsSidebar', function () {
      return {
        init: function () {
          var el = this.$el;
          el.scrollTop = Number(sessionStorage.getItem('docs.sidebar') || 0);
          el.addEventListener('scroll', function () {
            sessionStorage.setItem('docs.sidebar', el.scrollTop);
          }, { passive: true });
        },
      };
    });

    Alpine.data('docsSearch', function () {
      return {
        open: false,
        query: '',
        selected: 0,
        index: null,
        pending: false,

        // Fetched on first focus, not on page load. A reader who never
        // searches downloads none of it.
        load: function () {
          var self = this;
          if (this.index || this.pending) return;
          this.pending = true;

          fetch('{{ asset('docs-index.json') }}')
            .then(function (r) { return r.json(); })
            .then(function (json) { self.index = json; })
            .catch(function () { self.index = []; })
            .finally(function () { self.pending = false; });
        },

        // Remembering the element that opened the modal returns focus to the
        // right trigger without a ref per breakpoint — and it is also correct
        // when the modal was opened from the keyboard, from anywhere.
        origin: null,

        show: function () {
          var self = this;
          this.origin = document.activeElement;
          this.open = true;
          this.load();
          this.$nextTick(function () { self.$refs.input.focus(); });
        },

        hide: function () {
          this.open = false;
          this.query = '';
          this.selected = 0;
          if (this.origin && this.origin.focus) this.origin.focus();
        },

        // Substring scoring rather than Fuse.js: the index is two dozen pages
        // and no fuzzy library is installed. Every term must appear somewhere,
        // and where it appears decides the rank.
        get results() {
          var self = this;
          var terms = this.query.toLowerCase().split(/\s+/).filter(Boolean);

          if (! terms.length || ! this.index) return [];

          return this.index
            .map(function (page) { return { page: page, score: self.score(page, terms) }; })
            .filter(function (hit) { return hit.score > 0; })
            .sort(function (a, b) { return b.score - a.score; })
            .slice(0, 8)
            .map(function (hit) { return hit.page; });
        },

        score: function (page, terms) {
          var title = page.title.toLowerCase();
          var headings = page.headings.join(' ').toLowerCase();
          var body = (page.section + ' ' + page.excerpt).toLowerCase();
          var total = 0;

          for (var i = 0; i < terms.length; i++) {
            var hit = title.indexOf(terms[i]) !== -1 ? 100
              : headings.indexOf(terms[i]) !== -1 ? 40
                : body.indexOf(terms[i]) !== -1 ? 10 : 0;

            if (! hit) return 0;

            total += hit + (title.indexOf(terms[i]) === 0 ? 25 : 0);
          }

          return total;
        },

        move: function (step) {
          var count = this.results.length;
          if (count) this.selected = (this.selected + step + count) % count;
        },

        go: function () {
          var hit = this.results[this.selected];
          if (hit) window.location.href = '{{ url('/docs') }}/' + hit.slug;
        },
      };
    });
  });
</script>
@endpush
