{{-- Real <details>/<summary>: indexable, keyboard-accessible, open and close
     before a byte of JS runs. --}}
<section class="py-24 lg:py-32">
  <div class="mx-auto max-w-[1280px] px-6">
    <div class="grid gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:gap-20">
      <div>
        <p class="num mb-3 text-[12px] tracking-wider text-amber-500">QUESTIONS</p>
        <h2 class="display text-[2rem] font-bold leading-tight lg:text-[2.5rem]">The ones that actually get asked</h2>
      </div>

      <div class="divide-y divide-steel-700 border-y border-steel-700">
        <details class="group">
          <summary class="flex items-center justify-between gap-4 py-5 text-[15px] font-medium">
            What happens when a conversion is reversed?
            <svg class="faq-plus size-4 shrink-0 text-steel-400 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
          </summary>
          <p class="pb-5 pr-8 text-[14px] leading-relaxed text-steel-300">You get a second postback with the original <span class="num text-amber-400">trans_id</span>, a negative payout, and <span class="num text-amber-400">status=reversed</span>. Your balance is adjusted and the reversal is listed in your transactions with the date. We do not hide chargebacks and we do not net them out quietly.</p>
        </details>

        <details class="group">
          <summary class="flex items-center justify-between gap-4 py-5 text-[15px] font-medium">
            Why is there a 30-day hold?
            <svg class="faq-plus size-4 shrink-0 text-steel-400 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
          </summary>
          <p class="pb-5 pr-8 text-[14px] leading-relaxed text-steel-300">Networks reverse conversions for weeks after the fact, and we are paid on NET-30 ourselves. Paying you before that window closes means paying for fraud nobody has told us about yet. Publishers with a track record get a shorter window.</p>
        </details>

        <details class="group">
          <summary class="flex items-center justify-between gap-4 py-5 text-[15px] font-medium">
            Can I use my own interface instead of the hosted wall?
            <svg class="faq-plus size-4 shrink-0 text-steel-400 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
          </summary>
          <p class="pb-5 pr-8 text-[14px] leading-relaxed text-steel-300">Not yet. The hosted iframe is the only delivery method today, because it lets us run fraud checks and consent handling before the redirect. A REST offer catalogue is the next thing we are building.</p>
        </details>

        <details class="group">
          <summary class="flex items-center justify-between gap-4 py-5 text-[15px] font-medium">
            Do you accept EU traffic?
            <svg class="faq-plus size-4 shrink-0 text-steel-400 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
          </summary>
          <p class="pb-5 pr-8 text-[14px] leading-relaxed text-steel-300">Yes. EU users see a consent screen before the wall loads, and their choice is stored with a timestamp. We are not a registered IAB TCF vendor — if that is a hard requirement for you, we are not the right fit yet, and we would rather say so here than after you integrate.</p>
        </details>

        <details class="group">
          <summary class="flex items-center justify-between gap-4 py-5 text-[15px] font-medium">
            How fast is approval?
            <svg class="faq-plus size-4 shrink-0 text-steel-400 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
          </summary>
          <p class="pb-5 pr-8 text-[14px] leading-relaxed text-steel-300">You can sign up, create an app, and test the full integration immediately. A human reviews the account before it can earn — usually within a day. The manual gate is the cheapest fraud control we have, and it is why your payouts are not funding someone's bot farm.</p>
        </details>
      </div>
    </div>
  </div>
</section>
