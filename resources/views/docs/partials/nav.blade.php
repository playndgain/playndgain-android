{{-- The nav list. Rendered twice — sticky sidebar on desktop, inside a
     <details> on mobile — because duplicating eleven lines of markup is
     cheaper than a JS-driven slide-over that a reader with JS off cannot open,
     and docs/10 §11 only sanctions search, tabs and copy buttons degrading. --}}
@foreach ($nav as $group)
  <div class="mb-6">
    <p class="num mb-2 text-[11px] uppercase tracking-wider text-mist-400">{{ $group['section'] }}</p>
    <ul class="space-y-0.5 border-l border-line-800">
      @foreach ($group['pages'] as $page)
        <li>
          <a href="{{ url('/docs/'.$page['slug']) }}"
             @class([
               '-ml-px block border-l py-1 pl-3 text-[13.5px] transition-colors',
               'border-gold-400 font-medium text-gold-400' => $page['slug'] === $slug,
               'border-transparent text-mist-300 hover:border-line-600 hover:text-mist-50' => $page['slug'] !== $slug,
             ])
             @if ($page['slug'] === $slug) aria-current="page" @endif>{{ $page['title'] }}</a>
        </li>
      @endforeach
    </ul>
  </div>
@endforeach
