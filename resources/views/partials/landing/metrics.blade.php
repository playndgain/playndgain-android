{{-- Metric strip, in place of the partner-logo trust strip every B2B page runs.
     The figures are rendered at their final value: site.js knocks them back to
     zero and counts them in, so with JS off — or GSAP blocked — the band reads
     the real numbers instead of 0 / 0 / 0ms / 0. --}}
<section class="border-y border-steel-700 bg-obsidian-900">
  <div class="mx-auto grid max-w-[1280px] grid-cols-2 divide-steel-700 px-6 md:grid-cols-4 md:divide-x">
    <div class="reveal px-2 py-8 text-center md:py-10">
      <p class="num text-[28px] font-medium text-chalk" data-count="{{ $stats['offers'] }}">{{ number_format($stats['offers']) }}</p>
      <p class="mt-1 text-[12px] tracking-wide text-steel-400">Live offers</p>
    </div>
    <div class="reveal border-l border-steel-700 px-2 py-8 text-center md:border-l-0 md:py-10">
      <p class="num text-[28px] font-medium text-chalk" data-count="{{ $stats['countries'] }}">{{ number_format($stats['countries']) }}</p>
      <p class="mt-1 text-[12px] tracking-wide text-steel-400">Countries</p>
    </div>
    <div class="reveal border-t border-steel-700 px-2 py-8 text-center md:border-t-0 md:py-10">
      <p class="num text-[28px] font-medium text-chalk"><span data-count="{{ $stats['postback_ms'] }}">{{ number_format($stats['postback_ms']) }}</span><span class="text-steel-400">ms</span></p>
      <p class="mt-1 text-[12px] tracking-wide text-steel-400">Median postback</p>
    </div>
    <div class="reveal border-l border-t border-steel-700 px-2 py-8 text-center md:border-t-0 md:py-10">
      <p class="num text-[28px] font-medium text-chalk"><span data-count="{{ $stats['fraud_rules'] }}">{{ number_format($stats['fraud_rules']) }}</span></p>
      <p class="mt-1 text-[12px] tracking-wide text-steel-400">Fraud rules</p>
    </div>
  </div>
</section>
