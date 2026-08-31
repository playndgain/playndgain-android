{{-- How it works · trace event `redirect`. Timestamps, not 01/02/03: this
     content genuinely is a sequence, and elapsed time through the money path is
     the domain's own artifact rather than a count of sections. --}}
<section id="how" class="scroll-mt-24 py-24 lg:py-32">
  <div class="mx-auto max-w-[1280px] px-6">
    <div class="reveal max-w-[46ch]">
      <p class="num mb-3 text-[12px] tracking-wider text-amber-500">THE PATH</p>
      <h2 class="display text-[2rem] font-bold leading-tight lg:text-[2.5rem]">Three moves, then it runs itself</h2>
      <p class="mt-4 text-[16px] leading-relaxed text-steel-300">
        Timestamps below are elapsed time from the moment a user taps an offer.
        They are the same events you will read in your own logs.
      </p>
    </div>

    <div class="mt-14 grid gap-5 lg:grid-cols-3">
      {{-- step 1 --}}
      <article class="reveal lift rounded-2xl border border-steel-700 bg-steel-850 p-6">
        <div class="mb-5 flex items-center justify-between">
          <span class="num text-[11px] text-amber-500">t + 0s</span>
          <span class="num text-[11px] text-steel-400">once</span>
        </div>
        <h3 class="display text-[19px] font-semibold">Embed the wall</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">One iframe. No SDK, no build step, no app-store review.</p>
        <pre class="num mt-5 overflow-x-auto rounded-lg border border-steel-800 bg-obsidian-950 p-3 text-[10.5px] leading-relaxed text-steel-300"><code>&lt;iframe src="{{ \App\Support\Identity::url() }}/wall
  ?appid=<span class="text-amber-400">10042</span>&amp;userid=<span class="text-amber-400">USER_ID</span>"
  width="100%" height="800"&gt;&lt;/iframe&gt;</code></pre>
      </article>

      {{-- step 2 --}}
      <article class="reveal lift rounded-2xl border border-steel-700 bg-steel-850 p-6">
        <div class="mb-5 flex items-center justify-between">
          <span class="num text-[11px] text-amber-500">t + 2m 43s</span>
          <span class="num text-[11px] text-steel-400">per conversion</span>
        </div>
        <h3 class="display text-[19px] font-semibold">A user completes an offer</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">We check the click against {{ $stats['fraud_rules'] }} fraud rules before it ever counts.</p>
        <ul class="mt-5 space-y-2">
          <li class="flex items-center justify-between rounded-lg border border-steel-800 bg-obsidian-950 px-3 py-2"><span class="text-[12px] text-steel-300">Duplicate check</span><span class="num text-[11px] text-gain">pass</span></li>
          <li class="flex items-center justify-between rounded-lg border border-steel-800 bg-obsidian-950 px-3 py-2"><span class="text-[12px] text-steel-300">Datacenter IP</span><span class="num text-[11px] text-gain">pass</span></li>
          <li class="flex items-center justify-between rounded-lg border border-steel-800 bg-obsidian-950 px-3 py-2"><span class="text-[12px] text-steel-300">Geo vs targeting</span><span class="num text-[11px] text-gain">pass</span></li>
        </ul>
      </article>

      {{-- step 3 --}}
      <article class="reveal lift rounded-2xl border border-steel-700 bg-steel-850 p-6">
        <div class="mb-5 flex items-center justify-between">
          <span class="num text-[11px] text-amber-500">t + 30d</span>
          <span class="num text-[11px] text-steel-400">NET-30</span>
        </div>
        <h3 class="display text-[19px] font-semibold">You get paid</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">Earnings clear the chargeback window, then withdraw from $20.</p>
        <div class="mt-5 space-y-2.5 rounded-lg border border-steel-800 bg-obsidian-950 p-3">
          <div class="flex items-baseline justify-between"><span class="text-[12px] text-steel-400">Cleared</span><span class="num text-[13px] text-chalk">$1,284.310000</span></div>
          <div class="flex items-baseline justify-between"><span class="text-[12px] text-steel-400">Pending</span><span class="num text-[13px] text-steel-300">$412.090000</span></div>
          <div class="hairline h-px"></div>
          <div class="flex items-baseline justify-between"><span class="text-[12px] text-chalk">Withdrawable</span><span class="num text-[13px] font-medium text-gain">$1,284.310000</span></div>
        </div>
      </article>
    </div>
  </div>
</section>
