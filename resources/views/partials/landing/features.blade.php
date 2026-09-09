@php
    $features = \App\Support\LandingContent::features((int) ($stats['fraud_rules'] ?? 0));

    // Plain words where the old strip had shorthand: "6 dp / Precision" told a
    // publisher nothing they could act on. Monthly and the 1st are the schedule
    // and stay exactly as they are — no fixed day, no NET-30 (design.md §7).
    $termCards = [
        ['i-coin', 'Per action', 'What you earn', 'Paid for every verified conversion, never for a click.'],
        ['i-ledger', 'Monthly', 'When you are paid', 'Earnings clear on the 1st of the following month.'],
        ['i-bolt', '$'.$terms['min_payout'], 'Minimum payout', 'PayPal, crypto, bank or Wise.'],
        ['i-code', '6 decimals', 'Kept on every figure', 'Cent fractions are never rounded away.'],
    ];
@endphp

{{-- Two capabilities, each with a small visual in the product's own vocabulary
     that arrives in sequence after its card; then the terms in one compact
     strip, stated once. It was four cards, and two of the four repeated claims
     the proof band and the product section already make — see
     LandingContent::features(). --}}
<section class="on-ink pb-[var(--section)]" aria-labelledby="features-title">
  <div class="shell">
    <div class="max-w-[640px]" data-reveal>
      <p class="eyebrow">Before you trust us with traffic</p>
      <h2 id="features-title" class="display-2 mt-4">What happens to a click, and to the money after it.</h2>
    </div>

    <div class="mt-10 grid gap-5 md:grid-cols-2" data-reveal-group>
      @foreach ($features as $feature)
        <article class="card-ink card-lift flex min-w-0 flex-col p-7 lg:p-8">
          <div class="flex items-start justify-between gap-4">
            <span class="grid size-11 shrink-0 place-items-center rounded-[14px] bg-amber-400/12 text-amber-300">
              <svg class="size-5" aria-hidden="true"><use href="#{{ $feature['icon'] }}"/></svg>
            </span>
            <div class="min-w-0 flex-1" aria-hidden="true">
              @switch($feature['visual'])
                @case('verdicts')
                  <div class="seq flex flex-wrap justify-end gap-2 text-[13px] font-semibold">
                    <span class="pill bg-loss-500/15 text-loss-300"><i class="size-2 rounded-full bg-loss-500"></i>Datacenter IP · blocked</span>
                    <span class="pill pill-amber"><i class="size-2 rounded-full bg-amber-400"></i>Velocity 12/min · flagged</span>
                    <span class="pill bg-gain-500/15 text-gain-300"><i class="size-2 rounded-full bg-gain-500"></i>Clean · 0 rules hit</span>
                  </div>
                  @break
                @case('ledger')
                  {{-- The rows say what a ledger row is, not which offer paid
                       for one: the offer name that used to lead the credit
                       row was invented, and naming a real app here would
                       claim a conversion this installation never had. The
                       shape is the point — a credit and the negative row that
                       carries its trans_id — and money keeps its colours. --}}
                  <div class="seq num divide-y divide-ink-700 rounded-[14px] bg-ink-950 text-[13px] shadow-[inset_0_0_0_1px_var(--color-ink-700)]">
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5"><span class="min-w-0 truncate text-fog-400">Earning · trans_id 4f2a…</span><span class="font-semibold text-gain-300">+$0.091000</span></div>
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5"><span class="min-w-0 truncate text-fog-400">Chargeback · same trans_id</span><span class="font-semibold text-loss-300">−$0.091000</span></div>
                  </div>
                  @break
              @endswitch
            </div>
          </div>
          <h3 class="h3 mt-7">{{ $feature['title'] }}</h3>
          <p class="mt-3 text-[15px] leading-relaxed text-fog-400">{{ $feature['body'] }}</p>
        </article>
      @endforeach
    </div>

    <div id="terms" class="card-ink card-lg mt-6 scroll-mt-24 p-7 lg:p-8" data-reveal>
      <div class="grid gap-8 lg:grid-cols-[1.1fr_2fr] lg:items-center">
        <div>
          <p class="eyebrow">Terms</p>
          <h3 class="display-3 mt-3">Stated once, before you integrate.</h3>
          <p class="mt-3 text-[15px] text-fog-400">No tier to unlock, and no rate we revise after your traffic arrives.</p>
        </div>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-6 sm:grid-cols-4">
          @foreach ($termCards as [$icon, $figure, $label, $note])
            <div class="flex flex-col">
              <dt class="order-2 mt-2 text-[14px] font-semibold text-fog-50">{{ $label }}</dt>
              <dd class="order-1 flex items-center gap-2 text-[26px] font-bold leading-none tracking-[-0.02em] text-fog-50">
                <svg class="size-4 shrink-0 text-amber-400" aria-hidden="true"><use href="#{{ $icon }}"/></svg>{{ $figure }}
              </dd>
              <dd class="order-3 mt-1 text-[14px] leading-relaxed text-fog-500">{{ $note }}</dd>
            </div>
          @endforeach
        </dl>
      </div>
    </div>
  </div>
</section>
