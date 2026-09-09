@php
    $steps = \App\Support\LandingContent::steps();
    $count = count($nodes);
@endphp

{{-- Three steps and the shape of the network: the kinds of demand orbit the
     mark — never a partner by name (AGENTS.md) — and a dot runs the ring once,
     app to network to signed postback, when the diagram comes into view. The callback handler beside it is the real
     snippet from the docs, highlighted on the server; on a phone it folds
     behind a button (open by default from `md`, always visible without JS). --}}
<section id="how" class="on-ink section scroll-mt-20" aria-labelledby="how-title">
  <div class="shell">
    <div class="grid gap-6 lg:grid-cols-2 lg:items-end" data-reveal>
      <div>
        <p class="eyebrow">Integration</p>
        <h2 id="how-title" class="display-2 mt-4">Live in an afternoon.</h2>
      </div>
      <p class="lead text-fog-300 lg:max-w-[46ch] lg:justify-self-end">Embed an iframe, verify a signature, credit your player. Three things to remember — we document all three.</p>
    </div>

    <ol class="mt-12 grid gap-5 md:grid-cols-3" data-reveal-group>
      @foreach ($steps as $step)
        <li class="card-ink card-lift min-w-0 p-7">
          <p class="numeral text-[36px] text-amber-400">0{{ $loop->iteration }}</p>
          <h3 class="h3 mt-6">{{ $step['title'] }}</h3>
          <p class="mt-3 text-[15px] leading-relaxed text-fog-400">{{ $step['body'] }}</p>
        </li>
      @endforeach
    </ol>

    <div class="card-ink card-lg mt-6 grid gap-10 p-7 lg:grid-cols-[1fr_1.05fr] lg:items-center lg:p-10" data-reveal>
      <div>
        <p class="label text-fog-500">How the money moves</p>
        <div class="mx-auto mt-8 max-w-[400px] px-7 py-7">
          <div class="orbit" aria-hidden="true">
            <div class="orbit-ring"></div>
            <div class="orbit-dot"></div>
            @foreach ($nodes as $i => $node)
              @php
                  $angle = deg2rad(360 / $count * $i - 90);
                  $x = 50 + 50 * cos($angle);
                  $y = 50 + 50 * sin($angle);
              @endphp
              <span class="orbit-node" style="left: {{ round($x, 2) }}%; top: {{ round($y, 2) }}%; --i: {{ $i }}" title="{{ $node['label'] }}">
                <svg class="size-5" aria-hidden="true"><use href="#{{ $node['icon'] }}"/></svg>
              </span>
            @endforeach
            <div class="orbit-core grid w-[220px] place-items-center text-center">
              <span class="pill pill-ink" style="--i: 0">Your app · click</span>
              <x-brand-mark :size="56" :wordmark="false" class="my-4" style="--i: 1" />
              <span class="pill pill-amber" style="--i: 2">signed postback · +payout</span>
            </div>
          </div>
        </div>
        <p class="mt-5 text-center text-[14px] text-fog-500">{{ $name }} routes every click to the demand partner that pays best, and signs every postback back to you.</p>
      </div>

      <div class="code-card min-w-0" x-data="{ open: window.matchMedia('(min-width: 768px)').matches }" x-init="window.matchMedia('(min-width: 768px)').addEventListener('change', e => { if (e.matches) open = true })">
        <div class="flex flex-wrap items-center justify-between gap-3 text-[14px] text-fog-500">
          <span class="font-semibold text-fog-300">callback handler · php</span>
          <span class="num">{{ $snippet['lines'] }} lines</span>
        </div>
        <button type="button" x-cloak @click="open = !open" :aria-expanded="open" aria-controls="callback-code"
                class="btn btn-ghost-ink btn-sm mt-4 md:hidden" x-text="open ? 'Hide the handler' : 'Show the handler'">Show the handler</button>
        <pre id="callback-code" x-show="open" x-transition.opacity.duration.260ms class="mt-4 rounded-[14px] bg-ink-950 p-5 shadow-[inset_0_0_0_1px_var(--color-ink-700)]">{!! $snippet['html'] !!}</pre>
      </div>
    </div>
  </div>
</section>
