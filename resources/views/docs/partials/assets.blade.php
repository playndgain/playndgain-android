{{-- Prose styling and the docs Alpine components.

     Inline rather than in resources/css/app.css and resources/js/site.js
     because the HTML the Markdown parser emits has no class attributes to
     hang Tailwind utilities on — `.doc-prose h2` is the only way to reach it —
     and both of those files belong to the landing site.

     Literal hex, matching tokens.css, rather than var(--color-*): Tailwind v4
     only emits the theme variables it saw used, and a colour referenced from
     a Blade <style> block is not something it sees.

     The <script> is in `head`, which the layout stacks *before* @vite, so it
     registers on `alpine:init` before the deferred bundle calls Alpine.start(). --}}

@push('head')
<style>
  /* ── Prose ───────────────────────────────────────────────────────────── */
  .doc-prose { color: #A6ABBE; font-size: 15.5px; line-height: 1.75; }
  .doc-prose > * + * { margin-top: 1.1rem; }

  .doc-prose h2, .doc-prose h3 {
    font-family: "Sora Variable", "Sora", system-ui, sans-serif; font-weight: 700;
    font-optical-sizing: auto; letter-spacing: -0.02em; color: #F4F5FA; scroll-margin-top: 96px;
  }
  .doc-prose h2 { margin-top: 2.9rem; font-size: 1.65rem; line-height: 1.15; }
  .doc-prose h3 { margin-top: 2.1rem; font-size: 1.25rem; line-height: 1.2; }

  .doc-anchor {
    margin-left: .45em; color: #464C6A; text-decoration: none;
    font-weight: 400; opacity: 0; transition: opacity .15s;
  }
  h2:hover > .doc-anchor, h3:hover > .doc-anchor,
  .doc-anchor:focus-visible { opacity: 1; }

  .doc-prose strong { color: #F4F5FA; font-weight: 600; }
  .doc-prose em { color: #A6ABBE; }
  .doc-prose a { color: #FFB020; text-decoration: underline; text-underline-offset: 3px;
                 text-decoration-color: rgba(255,176,32,.35); }
  .doc-prose a:hover { color: #FFC554; text-decoration-color: #FFC554; }
  .doc-prose a.doc-anchor { text-decoration: none; }

  .doc-prose ul, .doc-prose ol { padding-left: 1.15rem; }
  .doc-prose ul { list-style: disc; }
  .doc-prose ol { list-style: decimal; }
  .doc-prose li { margin-top: .4rem; }
  .doc-prose li::marker { color: #7E839A; }

  .doc-prose hr { border: 0; height: 1px; margin: 2.4rem 0;
                  background: linear-gradient(90deg, #262A40, transparent); }

  .doc-prose blockquote {
    border-left: 2px solid #FFB020; background: #11131F;
    border-radius: 14px; padding: 1rem 1.25rem;
  }
  .doc-prose blockquote > * + * { margin-top: .8rem; }

  /* Inline code. Not the panel slab — that is for blocks. */
  .doc-prose :not(pre) > code {
    font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: .87em;
    background: #1A1D2E; border: 1px solid #262A40; border-radius: 8px;
    padding: .12em .4em; color: #F4F5FA; white-space: nowrap;
  }

  /* ── Tables ──────────────────────────────────────────────────────────── */
  .doc-table-wrap { overflow-x: auto; }
  .doc-prose table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  .doc-prose th, .doc-prose td {
    border-bottom: 1px solid #262A40; padding: .6rem .8rem;
    text-align: left; vertical-align: top;
  }
  .doc-prose th { color: #F4F5FA; font-weight: 600; font-size: 12px;
                  text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
  .doc-prose tbody tr:hover { background: rgba(26,29,46,.7); }

  /* ── Code blocks. The panel surface in every context — code reads better dark. ── */
  .doc-code { position: relative; margin-top: 1.1rem; }
  .doc-code pre {
    overflow-x: auto; background: #11131F; border: 1px solid #343954;
    border-radius: 14px; padding: 1rem 1.15rem; margin: 0;
    font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 12.5px; line-height: 1.7;
    color: #C3C7D6; tab-size: 4;
  }
  .doc-copy {
    position: absolute; top: 8px; right: 8px; z-index: 1;
    font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 11px;
    color: #7E839A; background: #11131F; border: 1px solid #262A40;
    border-radius: 8px; padding: 3px 9px; cursor: pointer;
    opacity: 0; transition: opacity .15s, color .15s, border-color .15s;
  }
  .doc-code:hover .doc-copy, .doc-copy:focus-visible { opacity: 1; }
  .doc-copy:hover { color: #F4F5FA; border-color: #464C6A; }

  /* tempest/highlight tokens, coloured the way the landing page paints its
     callback handler: a literal value is gold, a keyword is bright, a name is
     the body colour, a comment is faint. Go and Ruby have no grammar in the
     highlighter and fall through to the base colour, which is why the base
     colour is legible rather than a placeholder grey. */
  .hl-keyword, .hl-type, .hl-number, .hl-attribute { color: #F4F5FA; }
  .hl-value, .hl-literal { color: #FFB020; }
  .hl-variable, .hl-injection { color: #A6ABBE; }
  .hl-property, .hl-operator, .hl-generic { color: #A6ABBE; }
  .hl-comment { color: #7E839A; font-style: italic; }

  /* ── Language tabs ───────────────────────────────────────────────────── */
  .doc-tabs { margin-top: 1.1rem; }
  .doc-tabs-bar { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: -.4rem; }
  .doc-tabs-bar button {
    font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 12px; color: #7E839A;
    background: transparent; border: 1px solid transparent; border-radius: 8px;
    padding: 4px 10px; cursor: pointer; transition: color .15s, background-color .15s;
  }
  .doc-tabs-bar button:hover { color: #A6ABBE; }
  .doc-tabs-bar button.is-on { color: #F4F5FA; background: #1A1D2E; border-color: #262A40; }

  /* ── Postback URL builder ────────────────────────────────────────────── */
  .doc-builder {
    margin-top: 1.1rem; border: 1px solid #262A40; border-radius: 14px;
    background: #11131F; padding: 1.1rem; position: relative;
  }
  .doc-builder-label { font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 11px;
    text-transform: uppercase; letter-spacing: .06em; color: #7E839A; margin: 0 0 .45rem; }
  .doc-builder-label + * { margin-top: 0; }
  .doc-builder-input {
    width: 100%; background: #0A0B14; border: 1px solid #262A40; border-radius: 8px;
    padding: .5rem .7rem; color: #F4F5FA; font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 12.5px;
  }
  .doc-builder-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: .3rem .8rem; margin: 0 0 1rem;
  }
  .doc-builder-grid label { display: flex; align-items: center; gap: .5rem;
    font-size: 13px; color: #A6ABBE; cursor: pointer; }
  .doc-builder-grid input { accent-color: #FFB020; }
  .doc-builder-grid code { font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 12px; }
  .doc-builder-out {
    display: block; word-break: break-all; background: #0A0B14;
    border: 1px solid #262A40; border-radius: 8px; padding: .7rem .8rem;
    font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 12px; line-height: 1.65; color: #FFB020;
  }
  .doc-builder-copy {
    margin-top: .7rem; font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 11px;
    color: #7E839A; background: #1A1D2E; border: 1px solid #262A40;
    border-radius: 8px; padding: 4px 10px; cursor: pointer;
  }
  .doc-builder-copy:hover { color: #F4F5FA; border-color: #464C6A; }

  /* ── Copy page menu rows. Same shape as a search hit. ──────────────────── */
  .doc-action {
    display: block; width: 100%; text-align: left;
    border-radius: 12px; padding: .55rem .75rem;
    transition: background-color .15s;
  }
  .doc-action:hover { background: #1A1D2E; }

  /* ── Table of contents ───────────────────────────────────────────────── */
  .doc-toc a { display: block; padding: 3px 0 3px 11px; margin-left: -1px;
    border-left: 1px solid transparent; color: #7E839A; font-size: 12.5px; line-height: 1.5;
    transition: color .15s, border-color .15s; }
  .doc-toc a:hover { color: #A6ABBE; }
  .doc-toc a.is-on { color: #FFB020; border-left-color: #FFB020; }
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

    // The Copy page menu. The Markdown and the prompt are fetched on click
    // rather than embedded, so the page carries neither twice and the prompt
    // can pre-fill the reader's App ID server-side.
    Alpine.data('docsActions', function (slug) {
      return {
        open: false,
        label: 'Copy page',
        mcp: JSON.stringify({ mcpServers: { '{{ Str::slug(\App\Support\Identity::name()) }}-docs': { type: 'http', url: '{{ url('/mcp') }}' } } }, null, 2),
        toggle: function () { this.open = ! this.open; },
        close: function () {
          if (! this.open) return;
          this.open = false;
          this.$refs.toggle.focus();
        },
        done: function (message) {
          var self = this;
          this.open = false;
          this.label = message;
          setTimeout(function () { self.label = 'Copy page'; }, 1500);
        },
        copyText: function (text, message) {
          var self = this;
          navigator.clipboard.writeText(text).then(function () { self.done(message); });
        },
        copy: function (url, message) {
          var self = this;
          // Clipboard access must follow the click closely on Safari, so the
          // fetch is handed to the clipboard as a promise rather than awaited.
          var body = fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); });
          var write = window.ClipboardItem
            ? navigator.clipboard.write([new ClipboardItem({ 'text/plain': body.then(function (t) { return new Blob([t], { type: 'text/plain' }); }) })])
            : body.then(function (t) { return navigator.clipboard.writeText(t); });
          write.then(function () { self.done(message); }).catch(function () { self.done('Copy failed'); });
        },
        openIn: function (base) {
          return base + encodeURIComponent('Read ' + '{{ url('/docs') }}/' + slug + '.md and help me apply it to my integration.');
        },
      };
    });

    // The order the macros are documented in, so the built URL reads the same
    // way as the table above it however the reader ticked the boxes.
    var MACROS = ['user_id', 'trans_id', 'offer_id', 'offer_name', 'payout_usd',
      'currency_amount', 'app_id', 'country', 'ip', 'status', 'timestamp', 'signature'];

    Alpine.data('docsUrlBuilder', function () {
      return {
        base: 'https://yoursite.com/playndgain/callback',
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
