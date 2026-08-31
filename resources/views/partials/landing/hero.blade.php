{{-- Hero · trace event `click`. Must stay the first <section> in <main>:
     site.js exempts the first section's reveals from the scroll triggers
     because they belong to the load timeline instead. --}}
<section class="grain relative overflow-hidden pt-32 pb-20 lg:pt-40 lg:pb-28">
  <div class="hero-bg" aria-hidden="true">
    <img src="/img/hero-vault.webp"
         srcset="/img/hero-vault-sm.webp 960w, /img/hero-vault.webp 1920w"
         sizes="100vw" alt="" width="1920" height="1072" fetchpriority="high" decoding="async">
  </div>
  <div class="aurora" aria-hidden="true"><i></i><i></i></div>

  <div class="relative z-10 mx-auto max-w-[1280px] px-6">

    {{-- eyebrow: real figures, mono --}}
    <div class="reveal mx-auto mb-7 flex w-fit items-center gap-3 rounded-full border border-steel-700 bg-steel-850/70 px-4 py-1.5">
      <span class="relative flex size-1.5"><span class="absolute inline-flex size-full animate-ping rounded-full bg-gain opacity-70"></span><span class="relative inline-flex size-1.5 rounded-full bg-gain"></span></span>
      <span class="num text-[12px] tracking-tight text-steel-300">
        <span class="text-chalk">{{ number_format($stats['offers']) }}</span> offers
        <span class="mx-1.5 text-steel-600">·</span><span class="text-chalk">{{ number_format($stats['countries']) }}</span> countries
        <span class="mx-1.5 text-steel-600">·</span><span class="text-chalk">{{ number_format($stats['networks']) }}</span> networks live
      </span>
    </div>

    <h1 class="reveal display-x mx-auto max-w-[22ch] text-center text-[2.6rem] font-bold leading-[1.05] sm:text-[3.4rem] lg:text-[4rem]">
      Monetize your users.<br class="hidden sm:block">
      <span class="text-amber-500">Audit every cent.</span>
    </h1>

    <p class="reveal mx-auto mt-6 max-w-[58ch] text-center text-[17px] leading-relaxed text-steel-300">
      Aggregated offers from a dozen ad networks, delivered through one hosted offerwall.
      Signed callbacks, 30-day chargeback protection, NET-30 payouts.
    </p>

    <div class="reveal mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
      <a href="/dashboard/register" class="w-full rounded-xl bg-amber-500 px-7 py-3.5 text-center text-[15px] font-semibold text-obsidian-950 transition-all hover:bg-amber-400 sm:w-auto">Start earning</a>
      <a href="#integrate" class="group flex w-full items-center justify-center gap-2 rounded-xl border border-steel-700 px-7 py-3.5 text-[15px] font-medium text-chalk transition-colors hover:border-steel-600 hover:bg-steel-850 sm:w-auto">
        See the integration
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="transition-transform group-hover:translate-x-0.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
    </div>

    {{-- ── THE TRACE: device + orbiting real events ── --}}
    <div id="traceWrap" class="relative mx-auto mt-16 h-[680px] w-full max-w-[960px] sm:mt-20 lg:h-[640px]">

      {{-- device --}}
      <div id="device" class="absolute left-1/2 top-0 w-[320px] -translate-x-1/2">
        @include('partials.landing.wall-frame', ['compact' => true, 'live' => true])
      </div>

      {{-- orbiting trace chips: the real money path, not marketing claims --}}
      <article class="chip chip-l absolute left-0 top-6 w-[212px] rounded-xl border border-steel-700 bg-steel-850/95 p-3 backdrop-blur-sm lg:top-14">
        <p class="num mb-1 text-[10px] text-steel-400">14:32:07.221</p>
        <p class="text-[12px] font-medium">Click</p>
        <p class="num mt-1 text-[10px] text-steel-300">US · Android · 8f3a91c4</p>
      </article>

      <article class="chip chip-r absolute right-0 top-2 w-[196px] rounded-xl border border-steel-700 bg-steel-850/95 p-3 backdrop-blur-sm lg:top-8">
        <p class="num mb-1 text-[10px] text-steel-400">14:32:07.229</p>
        <p class="text-[12px] font-medium">Redirect → OGAds</p>
        <p class="num mt-1 text-[10px] text-steel-300">tracking url signed</p>
      </article>

      <article class="chip chip-r absolute right-0 top-[188px] w-[218px] rounded-xl border border-steel-700 bg-steel-850/95 p-3 backdrop-blur-sm lg:top-[176px]">
        <p class="num mb-1 text-[10px] text-steel-400">14:34:51.006</p>
        <p class="text-[12px] font-medium">Conversion</p>
        <div class="mt-1.5 flex items-baseline justify-between">
          <span class="num text-[10px] text-steel-300">txid 19624243</span>
          <span class="num text-[13px] font-medium text-chalk">$0.130000</span>
        </div>
      </article>

      <article class="chip chip-l absolute left-0 top-[196px] w-[218px] rounded-xl border border-steel-700 bg-steel-850/95 p-3 backdrop-blur-sm lg:top-[184px]">
        <p class="num mb-1 text-[10px] text-steel-400">14:34:51.014</p>
        <p class="text-[12px] font-medium">Ledger entry</p>
        <div class="mt-1.5 flex items-baseline justify-between">
          <span class="num text-[10px] text-steel-300">your share · 70%</span>
          <span class="num text-[13px] font-medium text-gain">+$0.091000</span>
        </div>
      </article>

      <article class="chip chip-l absolute left-0 top-[356px] w-[226px] rounded-xl border border-steel-700 bg-steel-850/95 p-3 backdrop-blur-sm lg:top-[344px]">
        <p class="num mb-1 text-[10px] text-steel-400">14:34:51.109</p>
        <p class="text-[12px] font-medium">Postback → your server</p>
        <div class="mt-1.5 flex items-center gap-2">
          <span class="num rounded bg-gain/12 px-1.5 py-0.5 text-[10px] text-gain">200 OK</span>
          <span class="num text-[10px] text-steel-300">{{ $stats['postback_ms'] }} ms</span>
        </div>
      </article>

      <article class="chip chip-r absolute right-0 top-[366px] w-[204px] rounded-xl border border-amber-500/45 bg-amber-950/45 p-3 backdrop-blur-sm lg:top-[354px]">
        <p class="num mb-1 text-[10px] text-amber-400/80">total 2m 43s</p>
        <p class="text-[12px] font-medium text-amber-400">User credited</p>
        <p class="num mt-1 text-[15px] font-medium text-chalk">91 Coins</p>
      </article>

      <p class="reveal absolute inset-x-0 bottom-0 text-center text-[13px] text-steel-400">
        One conversion, click to credited. Every step logged, every figure exportable.
      </p>
    </div>
  </div>
</section>
