@php
    /*
     * Auth lives in the publisher panel: /dashboard/login and /dashboard/register
     * (App\Filament\Publisher\Auth\Register). Hard-coded rather than route(): a
     * renamed panel path should break a link, not throw a 500 on the homepage.
     * Section links are bare fragments on the landing page and `/#…` on the
     * docs, the legal pages and the contact form (LandingContent::section()).
     */
    $links = \App\Support\LandingContent::nav();
@endphp

{{-- Sticky ink glass that firms up once the page has scrolled (site.js adds
     `is-scrolled`). The mobile menu is a transform/opacity panel under the
     bar: it locks the page behind it, moves focus to its first link, gives
     focus back to the button when it closes, and closes on Escape. Its
     trigger is x-cloak — with JS off the trigger never renders as a dead
     button and the <noscript> list carries the same links. --}}
<header data-nav
        x-data="{ open: false, toggle() { this.open = ! this.open; if (this.open) this.$nextTick(() => this.$refs.menu.querySelector('a')?.focus()) }, close(refocus = true) { if (! this.open) return; this.open = false; if (refocus) this.$refs.toggle.focus() } }"
        x-effect="document.documentElement.classList.toggle('menu-open', open)"
        @keydown.escape.window="close()"
        @resize.window.debounce.150ms="if (open && window.innerWidth >= 1024) close(false)"
        class="header-glass sticky top-0 z-40 border-b text-fog-50">
  <nav class="shell flex h-[72px] items-center gap-4 lg:gap-8" aria-label="Main">
    <a href="{{ url('/') }}" class="site-header-mark shrink-0 rounded-pill" aria-label="{{ \App\Support\Identity::name() }} — home">
      <x-brand-mark :size="28" />
    </a>

    <ul class="hidden items-center gap-7 text-[14.5px] font-medium text-fog-400 lg:flex">
      @foreach ($links as [$href, $label])
        <li><a href="{{ $href }}" data-nav-link class="inline-flex min-h-11 items-center transition-colors duration-[var(--motion-fast)] hover:text-fog-50">{{ $label }}</a></li>
      @endforeach
    </ul>

    <div class="ml-auto flex items-center gap-2 sm:gap-5">
      <a href="/dashboard/login" class="hidden min-h-11 items-center text-[14.5px] font-medium text-fog-400 transition-colors duration-[var(--motion-fast)] hover:text-fog-50 sm:inline-flex">Sign in</a>
      <a href="/dashboard/register" class="btn btn-primary btn-sm site-header-cta hidden min-[340px]:inline-flex px-4 sm:px-[18px]">Get started</a>

      <button type="button" x-ref="toggle" x-cloak @click="toggle()" :aria-expanded="open" aria-expanded="false" aria-controls="site-menu"
              class="-mr-2 grid size-11 place-items-center rounded-pill text-fog-50 transition-colors duration-[var(--motion-fast)] hover:bg-ink-800 lg:hidden" aria-label="Menu">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
          <path x-show="!open" d="M4 7h16M4 12h16M4 17h16"/>
          <path x-show="open" x-cloak d="M6 6l12 12M18 6L6 18"/>
        </svg>
      </button>
    </div>
  </nav>

  <div id="site-menu" x-ref="menu" x-show="open" x-cloak
       x-transition:enter="transition duration-[var(--motion-base)] ease-[var(--ease-out)]" x-transition:enter-start="-translate-y-2 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
       x-transition:leave="transition duration-[var(--motion-exit)] ease-[var(--ease-in)]" x-transition:leave-start="translate-y-0 opacity-100" x-transition:leave-end="-translate-y-2 opacity-0"
       class="site-menu absolute inset-x-0 top-full max-h-[calc(100dvh-72px)] overflow-y-auto border-b border-ink-700/60 lg:hidden">
    <ul class="shell space-y-1 py-4 text-[16px] font-medium">
      @foreach ($links as [$href, $label])
        <li><a href="{{ $href }}" data-nav-link @click="close(false)" class="block py-3 text-fog-300 transition-colors duration-[var(--motion-fast)] hover:text-fog-50">{{ $label }}</a></li>
      @endforeach
      <li class="sm:hidden"><a href="/dashboard/login" @click="close(false)" class="block py-3 text-fog-300">Sign in</a></li>
      <li class="pt-3"><a href="/dashboard/register" @click="close(false)" class="btn btn-primary w-full">Get started</a></li>
    </ul>
  </div>

  <noscript>
    <ul class="shell flex flex-wrap gap-x-5 gap-y-2 border-t border-ink-700/60 py-3 text-[14px] font-medium text-fog-300 lg:hidden">
      @foreach ($links as [$href, $label])
        <li><a href="{{ $href }}">{{ $label }}</a></li>
      @endforeach
      <li class="sm:hidden"><a href="/dashboard/login">Sign in</a></li>
    </ul>
  </noscript>
</header>
