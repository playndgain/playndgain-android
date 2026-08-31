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
              class="mb-6 flex w-full items-center gap-2 rounded-lg border border-steel-700 bg-steel-850 px-3 py-2 text-left text-[13px] text-steel-400 transition-colors hover:border-steel-600 hover:text-steel-300">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4" stroke-linecap="round"/>
        </svg>
        Search
        <span class="num ml-auto text-[11px] text-steel-600">&#8984;K</span>
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
                class="flex w-full items-center gap-2 rounded-xl border border-steel-700 bg-steel-850 px-4 py-3 text-left text-[14px] text-steel-400">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4" stroke-linecap="round"/>
          </svg>
          Search the documentation
        </button>

        <details class="doc-disclosure rounded-xl border border-steel-700 bg-steel-850">
          <summary class="flex items-center gap-2 px-4 py-3 text-[14px] font-medium text-chalk">Documentation</summary>
          <div class="border-t border-steel-700 px-4 pt-4 pb-1">@include('docs.partials.nav')</div>
        </details>

        @if ($page['toc'])
          <details class="doc-disclosure rounded-xl border border-steel-700 bg-steel-850">
            <summary class="flex items-center gap-2 px-4 py-3 text-[14px] font-medium text-chalk">On this page</summary>
            <div class="doc-toc border-t border-steel-700 px-4 py-3">
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
        <div class="mb-7 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-amber-500/25 bg-amber-950/40 px-4 py-3 text-[13px] text-steel-300">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#F5A524" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01" stroke-linecap="round"/>
          </svg>
          Showing values for <strong class="font-semibold text-chalk">{{ $credentials['app_name'] }}</strong>
          <span class="num text-amber-500">#{{ $credentials['app_id'] }}</span>
          <a href="/dashboard" class="ml-auto font-medium text-amber-500 transition-colors hover:text-amber-400">Reveal your secret key &rarr;</a>
        </div>
      @endif

      <h1 class="display text-[2.1rem] font-bold leading-tight tracking-tight text-chalk">{{ $page['title'] }}</h1>

      @if ($page['description'])
        <p class="mt-3 max-w-[62ch] text-[16px] leading-relaxed text-steel-300">{{ $page['description'] }}</p>
      @endif

      <article class="doc-prose mt-10 max-w-[72ch]">{!! $html !!}</article>

      {{-- ── Prev / next ───────────────────────────────────────────────── --}}
      <nav class="mt-16 grid gap-3 border-t border-steel-700 pt-8 sm:grid-cols-2" aria-label="Pagination">
        @foreach (['prev', 'next'] as $direction)
          @if ($neighbours[$direction])
            <a href="{{ url('/docs/'.$neighbours[$direction]['slug']) }}"
               class="lift rounded-xl border border-steel-700 bg-steel-850 px-4 py-3 {{ $direction === 'next' ? 'text-right sm:col-start-2' : '' }}">
              <span class="num block text-[11px] uppercase tracking-wider text-steel-400">{{ $direction === 'prev' ? 'Previous' : 'Next' }}</span>
              <span class="mt-0.5 block text-[14px] font-medium text-chalk">{{ $neighbours[$direction]['title'] }}</span>
            </a>
          @endif
        @endforeach
      </nav>

      @if (config('docs.edit_base'))
        <p class="mt-6 text-[13px] text-steel-400">
          <a href="{{ rtrim(config('docs.edit_base'), '/').'/'.$slug.'.md' }}" class="transition-colors hover:text-steel-300">Edit this page</a>
        </p>
      @endif
    </div>

    {{-- ── Table of contents ───────────────────────────────────────────── --}}
    <aside class="hidden lg:block lg:sticky lg:top-24" x-data="docsToc">
      @if ($page['toc'])
        <p class="num mb-2 text-[11px] uppercase tracking-wider text-steel-400">On this page</p>
        <div class="doc-toc border-l border-steel-700">
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
