{{-- What is on the wall, by kind. Demand partners are infrastructure and
     stay unnamed on every public surface (AGENTS.md); what a visitor can be
     shown is the shape of the inventory — what a player does to earn — and
     that is what these six cards say. --}}
<section id="inventory" class="on-paper scroll-mt-20 pt-[var(--section)] pb-14" aria-labelledby="inventory-title">
  <div class="shell">
    <div class="flex flex-wrap items-end justify-between gap-6" data-reveal>
      <div class="max-w-[600px]">
        <p class="eyebrow text-amber-700">Inventory</p>
        <h2 id="inventory-title" class="display-2 mt-4 text-coal-900">Offers your players will actually finish.</h2>
      </div>
      <p class="max-w-[42ch] text-[15px] leading-relaxed text-coal-600">Six kinds of offer, deduplicated and priced, in one catalogue.</p>
    </div>

    {{-- A list on paper, not six cards. Every one of these is the same shape —
         an icon, a name, a line — and six identical cards in a grid asks the
         reader to parse six containers to read six sentences. One bordered
         card holds them all, and the rows are divided by the hairline that
         does that job everywhere else on paper. --}}
    <ul class="card-paper mt-10 grid grid-cols-1 divide-y divide-paper-200 sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-3" data-reveal-group>
      @foreach ($categories as $category)
        <li class="flex min-w-0 items-start gap-4 p-5 sm:p-6">
          <span class="grid size-11 shrink-0 place-items-center rounded-[14px] bg-ink-950 text-amber-400">
            <svg class="size-5" aria-hidden="true"><use href="#{{ $category['icon'] }}"/></svg>
          </span>
          <div class="min-w-0">
            <p class="text-[16px] font-semibold text-coal-900">{{ $category['name'] }}</p>
            <p class="mt-1 text-[14px] leading-relaxed text-coal-600">{{ $category['desc'] }}</p>
          </div>
        </li>
      @endforeach
    </ul>
  </div>
</section>
