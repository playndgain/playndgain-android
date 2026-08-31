{{-- Payouts · trace event `payout`. Amber band, also `data-light`. --}}
<section id="terms" data-light class="scroll-mt-24 bg-amber-500 py-20 text-obsidian-950 lg:py-24">
  <div class="mx-auto max-w-[1280px] px-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <h2 class="display text-[2rem] font-bold leading-tight lg:text-[2.5rem]">The terms, up front</h2>
      <p class="max-w-[42ch] text-[15px] leading-relaxed text-obsidian-950/70">
        No tier you have to unlock, no rate we quote after you integrate.
      </p>
    </div>

    <dl class="mt-12 grid grid-cols-2 gap-x-6 gap-y-10 border-t border-obsidian-950/15 pt-10 lg:grid-cols-4">
      <div><dt class="num text-[2.6rem] font-medium leading-none">70<span class="text-[1.6rem]">%</span></dt><dd class="mt-2.5 text-[13px] leading-snug text-obsidian-950/70">Revenue share, standard. Negotiable on volume.</dd></div>
      <div><dt class="num text-[2.6rem] font-medium leading-none">30<span class="text-[1.6rem]">d</span></dt><dd class="mt-2.5 text-[13px] leading-snug text-obsidian-950/70">Hold window. Matches the chargeback risk we carry.</dd></div>
      <div><dt class="num text-[2.6rem] font-medium leading-none">$20</dt><dd class="mt-2.5 text-[13px] leading-snug text-obsidian-950/70">Minimum payout. PayPal, crypto, bank, Wise.</dd></div>
      <div><dt class="num text-[2.6rem] font-medium leading-none">6<span class="text-[1.6rem]">dp</span></dt><dd class="mt-2.5 text-[13px] leading-snug text-obsidian-950/70">Decimal precision on every figure. No silent rounding.</dd></div>
    </dl>

    <p class="mt-10 max-w-[62ch] text-[14px] leading-relaxed text-obsidian-950/75">
      <strong class="font-semibold">Chargebacks are shown, not hidden.</strong>
      When a network reverses a conversion we send you a second postback with a negative
      amount and the original transaction ID. Your dashboard shows exactly which ones and when.
    </p>

    {{-- The payout calculator (docs/09 §7). Gated on `x-cloak`, not on the
         `js-ready` class site.js sets: js-ready is deliberately withheld under
         prefers-reduced-motion, so gating on it would hide the calculator from
         exactly the visitors who asked for less movement rather than less
         function. x-cloak is the gate the stylesheet already carries for Alpine
         widgets — with JS off the terms above still read and no inert, empty
         box ships below them.

         Every constant is the platform's own and is printed beside the result:
         the share is config('advault.default_rev_share'), the payout per
         conversion is the mean across the offers that are live right now,
         queried by LandingController. A publisher who asks where the number
         came from can read the answer without opening a console — and can
         check both figures against their own dashboard after they integrate.

         It runs on a dark panel because the band is amber-500 and so is the
         global focus ring: an amber outline on amber ground is no outline at
         all, and these are the first focusable elements in this section. --}}
    @php $revShare = (float) config('advault.default_rev_share'); @endphp

    <div x-cloak
         x-data="{
           volume: 100000,
           rate: 2,
           get conversions() { return Math.max(0, this.volume || 0) * Math.min(100, Math.max(0, this.rate || 0)) / 100 },
           get earnings() { return this.conversions * {{ $stats['avg_payout'] }} * {{ $revShare }} / 100 },
           money(n) { return n.toLocaleString('en-US', { style: 'currency', currency: 'USD' }) },
           count(n) { return Math.round(n).toLocaleString('en-US') },
         }"
         class="mt-14 overflow-hidden rounded-2xl bg-obsidian-950 text-chalk">

      <div class="p-6 sm:p-8">
        <p class="num text-[12px] tracking-wider text-amber-500">PAYOUT CALCULATOR</p>

        <div class="mt-6 grid gap-x-6 gap-y-7 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.5fr] lg:items-end">
          <div>
            <label for="calc-volume" class="block text-[13px] text-steel-300">Monthly wall sessions</label>
            <input id="calc-volume" type="number" min="0" step="1000" inputmode="numeric" x-model.number="volume"
                   class="num mt-2.5 w-full rounded-xl border border-steel-700 bg-steel-850 px-3.5 py-2.5 text-[15px] text-chalk">
          </div>

          <div>
            <label for="calc-rate" class="block text-[13px] text-steel-300">Conversion rate (%)</label>
            <input id="calc-rate" type="number" min="0" max="100" step="0.1" inputmode="decimal" x-model.number="rate"
                   class="num mt-2.5 w-full rounded-xl border border-steel-700 bg-steel-850 px-3.5 py-2.5 text-[15px] text-chalk">
          </div>

          <div class="sm:col-span-2 lg:col-span-1 lg:text-right">
            <p id="calc-result-label" class="text-[13px] text-steel-300">Your share, per month</p>
            <p class="num mt-2.5 text-[2.2rem] font-medium leading-none text-amber-500"
               aria-live="polite" aria-labelledby="calc-result-label" x-text="money(earnings)"></p>
            <p class="num mt-2.5 text-[12px] leading-relaxed text-steel-400">
              <span x-text="count(conversions)"></span> conversions
              × ${{ number_format($stats['avg_payout'], 2) }} average payout
              × {{ $revShare }}% share
            </p>
          </div>
        </div>
      </div>

      <p class="border-t border-steel-800 px-6 py-4 text-[13px] leading-relaxed text-steel-300 sm:px-8">
        An estimate, not a quote. The average payout is the mean across the
        {{ number_format($stats['offers']) }} offers live right now — your own mix of geos, devices
        and categories moves it in both directions, and reversals are netted out of what you are
        actually paid.
      </p>
    </div>
  </div>
</section>
