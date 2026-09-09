@php
    $results = \App\Support\LandingContent::results();
@endphp

{{-- Worked playbooks, each tagged "Example": what the three most common
     placements look like in the product, not accounts we are claiming. --}}
<section class="on-paper section" aria-labelledby="results-title">
  <div class="shell">
    <div class="flex flex-wrap items-end justify-between gap-6" data-reveal>
      <div class="max-w-[600px]">
        <p class="eyebrow text-amber-700">Playbooks</p>
        <h2 id="results-title" class="display-2 mt-4 text-coal-900">What a first month looks like.</h2>
      </div>
      <p class="max-w-[40ch] text-[15px] leading-relaxed text-coal-600">Worked examples of the three placements we see most. Your figures are your own — the dashboard shows them from the first click.</p>
    </div>

    <div class="mt-10 grid gap-6 lg:grid-cols-3" data-reveal-group>
      @foreach ($results as $result)
        <article class="card-paper card-lift flex min-w-0 flex-col overflow-hidden">
          <div class="flex-1 p-7">
            <div class="flex items-center justify-between gap-3">
              <span class="pill pill-paper"><svg class="size-4 text-amber-700" aria-hidden="true"><use href="#{{ $result['icon'] }}"/></svg>{{ $result['category'] }}</span>
              <span class="label text-coal-600">Example</span>
            </div>
            <h3 class="display-3 mt-5 text-coal-900">{{ $result['title'] }}</h3>
            <p class="mt-3 text-[15px] leading-relaxed text-coal-600">{{ $result['body'] }}</p>
            <ul class="mt-4 flex flex-wrap gap-2 text-[14px] font-medium text-coal-700">
              @foreach ($result['points'] as $point)
                <li class="rounded-pill bg-paper-100 px-3 py-1.5">{{ $point }}</li>
              @endforeach
            </ul>
          </div>
          <div class="mx-5 mb-5">
            <div class="shot aspect-[16/10] rounded-[16px]">
              <img src="/img/landing/{{ $result['image'] }}-card.webp" alt="{{ $result['alt'] }}" width="1650" height="1238" loading="lazy" decoding="async" class="h-full w-full object-cover object-top">
            </div>

            {{-- Every capture on this page comes from the local demo account,
                 and says so. Not the same sentence on all three: the panels are
                 a real panel with seeded traffic behind them, and the wall is a
                 demo placement whose rows are the catalogue as it really is,
                 each carrying the artwork its own app publishes. --}}
            <p class="mt-2.5 text-[13px] font-medium text-coal-600">{{ $result['note'] }}</p>
          </div>
        </article>
      @endforeach
    </div>
  </div>
</section>
