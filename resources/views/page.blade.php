@extends('layouts.site')

@section('title', $page['title'].' — '.\App\Support\Identity::name())
@section('description', $page['description'])

{{-- The prose stylesheet and the copy/ToC Alpine components live with the docs
     because the Markdown parser emits HTML with no classes to hang utilities
     on — `.doc-prose h2` is the only way to reach it. These pages come out of
     the same parser, so they take the same stylesheet rather than a second one
     that would start identical and then drift. --}}
@include('docs.partials.assets')

@section('content')
<main id="main" class="mx-auto max-w-[1000px] px-6 pt-28 pb-24">

  <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_180px] lg:items-start lg:gap-12">

    <div class="min-w-0">
      <h1 class="display-2 text-[2.1rem] text-mist-50">{{ $page['title'] }}</h1>

      @if ($page['description'])
        <p class="mt-3 max-w-[62ch] text-[16px] leading-relaxed text-mist-300">{{ $page['description'] }}</p>
      @endif

      <article class="doc-prose mt-10 max-w-[72ch]">{!! $page['html'] !!}</article>
    </div>

    {{-- Marked aria-hidden would be wrong — it is a real navigation aid on a
         page this long — so it is a labelled landmark instead. --}}
    @if ($page['toc'])
      <aside class="mt-14 hidden lg:sticky lg:top-28 lg:mt-0 lg:block" x-data="docsToc" aria-label="On this page">
        <p class="num mb-2 text-[11px] uppercase tracking-wider text-mist-400">On this page</p>
        <div class="doc-toc border-l border-line-800">
          @foreach ($page['toc'] as $item)
            <a href="#{{ $item['id'] }}" class="lvl-{{ $item['level'] }}"
               :class="{ 'is-on': active === '{{ $item['id'] }}' }">{{ $item['text'] }}</a>
          @endforeach
        </div>
      </aside>
    @endif
  </div>
</main>
@endsection
