@extends('layouts.site')

@section('title', $page['title'].' — '.\App\Support\Identity::name().' docs')
@section('description', $page['description'])

@include('docs.partials.assets')

@section('content')
<main id="main" class="mx-auto max-w-[1360px] px-6 pt-24 pb-24"
      x-data="docsSearch"
      @keydown.window.cmd.k.prevent="show()"
      @keydown.window.ctrl.k.prevent="show()"
      @keydown.escape.window="open && hide()">

  <div class="lg:grid lg:grid-cols-[228px_minmax(0,1fr)_208px] lg:gap-10 lg:items-start">

    {{-- ── Nav sidebar ─────────────────────────────────────────────────── --}}
    <aside class="hidden lg:block lg:sticky lg:top-24">
      <button type="button" @click="show()"
              class="mb-6 flex w-full items-center gap-2 rounded-control border border-line-800 bg-ink-900 px-3 py-2 text-left text-[13px] text-mist-400 transition-colors hover:border-line-600 hover:text-mist-300">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4" stroke-linecap="round"/>
        </svg>
        Search
        <span class="num ml-auto text-[11px] text-mist-500">&#8984;K</span>
      </button>

      <div x-data="docsSidebar" class="max-h-[calc(100vh-11rem)] overflow-y-auto pr-2">
        @include('docs.partials.nav')
      </div>
    </aside>

    {{-- ── Content ─────────────────────────────────────────────────────── --}}
    <div class="min-w-0">

      {{-- Mobile nav and contents. Native disclosures — a reader with JS off
           can still move around the documentation. --}}
      <div class="mb-8 space-y-2 lg:hidden">
        <button type="button" x-cloak @click="show()"
                class="flex w-full items-center gap-2 rounded-card border border-line-800 bg-ink-900 px-4 py-3 text-left text-[14px] text-mist-400">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4" stroke-linecap="round"/>
          </svg>
          Search the documentation
        </button>

        <details class="doc-disclosure rounded-card border border-line-800 bg-ink-900">
          <summary class="flex items-center gap-2 px-4 py-3 text-[14px] font-medium text-mist-50">Documentation</summary>
          <div class="border-t border-line-800 px-4 pt-4 pb-1">@include('docs.partials.nav')</div>
        </details>

        @if ($page['toc'])
          <details class="doc-disclosure rounded-card border border-line-800 bg-ink-900">
            <summary class="flex items-center gap-2 px-4 py-3 text-[14px] font-medium text-mist-50">On this page</summary>
            <div class="doc-toc border-t border-line-800 px-4 py-3">
              @foreach ($page['toc'] as $item)
                <a href="#{{ $item['id'] }}" class="lvl-{{ $item['level'] }}">{{ $item['text'] }}</a>
              @endforeach
            </div>
          </details>
        @endif
      </div>

      @if ($credentials)
        {{-- The secret is deliberately absent. Docs pages are cacheable,
             shareable and screenshot-able; the reveal lives in the panel. --}}
        <div class="mb-7 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-card border border-gold-400/25 bg-gold-950 px-4 py-3 text-[13px] text-mist-300">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#DFC08A" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01" stroke-linecap="round"/>
          </svg>
          Showing values for <strong class="font-semibold text-mist-50">{{ $credentials['app_name'] }}</strong>
          <span class="num text-gold-400">#{{ $credentials['app_id'] }}</span>
          <a href="/dashboard" class="ml-auto font-medium text-gold-400 transition-colors hover:text-gold-300">Reveal your secret key &rarr;</a>
        </div>
      @endif

      <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
        <h1 class="display-2 text-[2.1rem] text-mist-50">{{ $page['title'] }}</h1>

        {{-- Copy page menu. x-cloak: with JS off there is no clipboard, and
             the page itself is already on screen. The text routes it reads
             from carry no secret; see DocsTextController. --}}
        <div x-data="docsActions('{{ $slug }}')" x-cloak @keydown.escape.stop="close()" class="relative shrink-0 sm:mt-2">
          <button type="button" x-ref="toggle" @click="toggle()" :aria-expanded="open" aria-expanded="false" aria-haspopup="menu" aria-controls="docs-actions"
                  class="inline-flex min-h-9 items-center gap-2 rounded-control border border-line-800 bg-ink-900 px-3 py-1.5 text-[13px] text-mist-400 transition-colors duration-[var(--motion-fast)] hover:border-line-600 hover:text-mist-300">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a1 1 0 0 1 1-1h9" stroke-linecap="round"/>
            </svg>
            <span x-text="label">Copy page</span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" class="transition-transform duration-[var(--motion-fast)]" :class="open && 'rotate-180'">
              <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <div id="docs-actions" role="menu" x-show="open" x-cloak @click.outside="close()"
               x-transition:enter="transition duration-[var(--motion-base)] ease-[var(--ease-out)]" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
               x-transition:leave="transition duration-[var(--motion-exit)] ease-[var(--ease-in)]" x-transition:leave-start="translate-y-0 opacity-100" x-transition:leave-end="-translate-y-1 opacity-0"
               class="absolute left-0 z-30 mt-2 w-[min(300px,calc(100vw-3rem))] sm:left-auto sm:right-0 rounded-card border border-line-800 bg-ink-900 p-2 shadow-card">
            <p class="num px-3 pt-1.5 pb-1 text-[11px] uppercase tracking-wider text-mist-400">Copy</p>
            <button type="button" role="menuitem" @click="copy('{{ url('/docs/'.$slug.'.md') }}', 'Page copied')" class="doc-action">
              <span class="block text-[13.5px] font-medium text-mist-50">Page as Markdown</span>
              <span class="block text-[12px] text-mist-400">This page, ready to paste into a chat</span>
            </button>
            <button type="button" role="menuitem" @click="copy('{{ url('/docs/prompt.txt') }}', 'Prompt copied')" class="doc-action">
              <span class="block text-[13.5px] font-medium text-mist-50">Integration prompt</span>
              <span class="block text-[12px] text-mist-400">The whole callback contract, for a coding assistant</span>
            </button>
            <button type="button" role="menuitem" @click="copyText(mcp, 'MCP config copied')" class="doc-action">
              <span class="block text-[13.5px] font-medium text-mist-50">MCP server config</span>
              <span class="block text-[12px] text-mist-400">Lets Claude Code, Cursor or any MCP client read these docs</span>
            </button>
            <button type="button" role="menuitem" @click="copyText('{{ url('/docs/llms.txt') }}', 'Link copied')" class="doc-action">
              <span class="block text-[13.5px] font-medium text-mist-50">llms.txt link</span>
              <span class="block text-[12px] text-mist-400">Every page as one file, for tools that index docs</span>
            </button>

            <p class="num mt-1 border-t border-line-800 px-3 pt-2.5 pb-1 text-[11px] uppercase tracking-wider text-mist-400">Open in</p>
            <a role="menuitem" :href="openIn('https://claude.ai/new?q=')" target="_blank" rel="noopener" class="doc-action">
              <span class="block text-[13.5px] font-medium text-mist-50">Claude</span>
            </a>
            <a role="menuitem" :href="openIn('https://chatgpt.com/?q=')" target="_blank" rel="noopener" class="doc-action">
              <span class="block text-[13.5px] font-medium text-mist-50">ChatGPT</span>
            </a>
          </div>
        </div>
      </div>

      @if ($page['description'])
        <p class="mt-3 max-w-[62ch] text-[16px] leading-relaxed text-mist-300">{{ $page['description'] }}</p>
      @endif

      <article class="doc-prose mt-10 max-w-[72ch]">{!! $html !!}</article>

      {{-- ── Prev / next ───────────────────────────────────────────────── --}}
      <nav class="mt-16 grid gap-3 border-t border-line-800 pt-8 sm:grid-cols-2" aria-label="Pagination">
        @foreach (['prev', 'next'] as $direction)
          @if ($neighbours[$direction])
            <a href="{{ url('/docs/'.$neighbours[$direction]['slug']) }}"
               class="transition-colors hover:border-line-600 rounded-card border border-line-800 bg-ink-900 px-4 py-3 {{ $direction === 'next' ? 'text-right sm:col-start-2' : '' }}">
              <span class="num block text-[11px] uppercase tracking-wider text-mist-400">{{ $direction === 'prev' ? 'Previous' : 'Next' }}</span>
              <span class="mt-0.5 block text-[14px] font-medium text-mist-50">{{ $neighbours[$direction]['title'] }}</span>
            </a>
          @endif
        @endforeach
      </nav>

      @if (config('docs.edit_base'))
        <p class="mt-6 text-[13px] text-mist-400">
          <a href="{{ rtrim(config('docs.edit_base'), '/').'/'.$slug.'.md' }}" class="transition-colors hover:text-mist-300">Edit this page</a>
        </p>
      @endif
    </div>

    {{-- ── Table of contents ───────────────────────────────────────────── --}}
    <aside class="hidden lg:block lg:sticky lg:top-24" x-data="docsToc">
      @if ($page['toc'])
        <p class="num mb-2 text-[11px] uppercase tracking-wider text-mist-400">On this page</p>
        <div class="doc-toc border-l border-line-800">
          @foreach ($page['toc'] as $item)
            <a href="#{{ $item['id'] }}" class="lvl-{{ $item['level'] }}"
               :class="{ 'is-on': active === '{{ $item['id'] }}' }">{{ $item['text'] }}</a>
          @endforeach
        </div>
      @endif
    </aside>
  </div>

  @include('docs.partials.search')
</main>
@endsection
