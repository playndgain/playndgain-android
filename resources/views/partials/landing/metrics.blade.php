@php
    // Four capabilities, pulled up over the hero's foot. No counters: see
    // LandingContent::proofs() for why the figures left this band and where
    // they still live. The minimum payout is the one number here, and it is
    // read from the settings table like every other stated term.
    $proofs = \App\Support\LandingContent::proofs($terms['min_payout']);
@endphp

{{-- The proof band. Nothing in it has a sample floor, a window or a
     denominator, so there is no branch where a tile has to stand in for a
     figure it cannot defend — every tile is the same tile. --}}
<section class="on-ink" aria-label="What the product does">
  <div class="shell relative z-10 -mt-16 grid grid-cols-2 gap-4 pb-16 lg:-mt-20 lg:grid-cols-4 lg:gap-5" data-reveal-group>
    @foreach ($proofs as $proof)
      <div class="card-ink card-lift metric-tile min-w-0 p-4 sm:p-6 lg:p-7">
        <span class="grid size-11 shrink-0 place-items-center rounded-[14px] bg-amber-400/12 text-amber-300">
          <svg class="size-5" aria-hidden="true"><use href="#{{ $proof['icon'] }}"/></svg>
        </span>

        <p class="mt-6 text-[19px] font-bold leading-tight tracking-[-0.02em] text-fog-50 sm:text-[24px]">{{ $proof['value'] }}</p>
        <p class="mt-2 text-[15px] font-semibold text-fog-300">{{ $proof['label'] }}</p>
        <p class="mt-1.5 text-[14px] leading-relaxed text-fog-500">{{ $proof['note'] }}</p>
      </div>
    @endforeach
  </div>
</section>
