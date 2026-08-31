{{-- Why AdVault · trace event `ledger`. Each card names a mechanism and a
     number. "Best-in-class fraud detection" is what the competitors write. --}}
<section class="py-24 lg:py-32">
  <div class="mx-auto max-w-[1280px] px-6">
    <div class="reveal max-w-[46ch]">
      <p class="num mb-3 text-[12px] tracking-wider text-amber-500">WHAT YOU GET</p>
      <h2 class="display text-[2rem] font-bold leading-tight lg:text-[2.5rem]">Mechanisms, not adjectives</h2>
    </div>

    <div class="mt-14 grid gap-px overflow-hidden rounded-2xl border border-steel-700 bg-steel-700 sm:grid-cols-2 lg:grid-cols-3">

      <article class="feat group bg-obsidian-950 p-7 transition-colors hover:bg-steel-850 hover:shadow-[inset_0_1px_0_rgba(245,165,36,.14)]">
        <div class="mb-5 flex size-9 items-center justify-center rounded-lg border border-steel-700 bg-steel-850 text-amber-500">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M12 3l7 4v5c0 4.4-3 8.3-7 9-4-.7-7-4.6-7-9V7l7-4z" stroke-linejoin="round"/><path d="M9.5 12l1.8 1.8 3.4-3.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <h3 class="display text-[17px] font-semibold">{{ $stats['fraud_rules'] }} fraud rules, all explainable</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">Every block writes an event with the rule and the inputs. Ask why a user wasn't credited and the answer is one query away.</p>
      </article>

      <article class="feat group bg-obsidian-950 p-7 transition-colors hover:bg-steel-850 hover:shadow-[inset_0_1px_0_rgba(245,165,36,.14)]">
        <div class="mb-5 flex size-9 items-center justify-center rounded-lg border border-steel-700 bg-steel-850 text-amber-500">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01" stroke-linecap="round"/></svg>
        </div>
        <h3 class="display text-[17px] font-semibold">A dozen networks, one integration</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">The same offer on three networks shows once, at the highest payout. You integrate with us; we handle the rest.</p>
      </article>

      <article class="feat group bg-obsidian-950 p-7 transition-colors hover:bg-steel-850 hover:shadow-[inset_0_1px_0_rgba(245,165,36,.14)]">
        <div class="mb-5 flex size-9 items-center justify-center rounded-lg border border-steel-700 bg-steel-850 text-amber-500">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <h3 class="display text-[17px] font-semibold">Callbacks you can verify</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">Every postback is signed <span class="num text-[13px] text-amber-400">sha256(trans_id + payout + secret)</span>. Three lines to verify, documented in five languages.</p>
      </article>

      <article class="feat group bg-obsidian-950 p-7 transition-colors hover:bg-steel-850 hover:shadow-[inset_0_1px_0_rgba(245,165,36,.14)]">
        <div class="mb-5 flex size-9 items-center justify-center rounded-lg border border-steel-700 bg-steel-850 text-amber-500">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M4 20v-5.5M4 8.5V4M12 20v-9M12 6V4M20 20v-3M20 11V4" stroke-linecap="round"/><circle cx="4" cy="11.5" r="2"/><circle cx="12" cy="8.5" r="2"/><circle cx="20" cy="14" r="2"/></svg>
        </div>
        <h3 class="display text-[17px] font-semibold">Retries with a schedule you can read</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">1m, 5m, 30m, 2h, 6h. Every attempt logged with the status and body your server returned. Re-fire any of them by hand.</p>
      </article>

      <article class="feat group bg-obsidian-950 p-7 transition-colors hover:bg-steel-850 hover:shadow-[inset_0_1px_0_rgba(245,165,36,.14)]">
        <div class="mb-5 flex size-9 items-center justify-center rounded-lg border border-steel-700 bg-steel-850 text-amber-500">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3.5 12h17M12 3.2a15 15 0 010 17.6M12 3.2a15 15 0 000 17.6"/></svg>
        </div>
        <h3 class="display text-[17px] font-semibold">Targeting that respects the offer</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">Geo, device, and OS filtering on every render. A user never sees an offer they cannot complete — the fastest way to lose them.</p>
      </article>

      <article class="feat group bg-obsidian-950 p-7 transition-colors hover:bg-steel-850 hover:shadow-[inset_0_1px_0_rgba(245,165,36,.14)]">
        <div class="mb-5 flex size-9 items-center justify-center rounded-lg border border-steel-700 bg-steel-850 text-amber-500">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M12 3v18M8 6.5h6.5a3 3 0 010 6H8m0 0h7a3 3 0 010 6H8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <h3 class="display text-[17px] font-semibold">An append-only ledger</h3>
        <p class="mt-2 text-[14px] leading-relaxed text-steel-300">Balances are derived from entries that are never edited or deleted. Corrections are new rows. Your history cannot be rewritten — including by us.</p>
      </article>

    </div>
  </div>
</section>
