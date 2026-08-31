{{-- Integration · trace event `postback`. All highlighting is spans in the
     markup — zero highlighter in the client bundle (docs/09 §8).
     The comments inside the samples are --color-steel-300 italic, not
     --steel-600: at 2.2:1 the two sentences that prevent the most common
     integration failures were unreadable. --}}
<section id="integrate" x-data="{ lang: 'php' }" class="scroll-mt-24 border-t border-steel-700 bg-obsidian-900 py-24 lg:py-32">
  <div class="mx-auto max-w-[1280px] px-6">
    <div class="grid gap-12 lg:grid-cols-[1fr_1.15fr] lg:gap-16">

      <div>
        <p class="num mb-3 text-[12px] tracking-wider text-amber-500">INTEGRATION</p>
        <h2 class="display text-[2rem] font-bold leading-tight lg:text-[2.5rem]">An afternoon, not a sprint</h2>
        <p class="mt-4 max-w-[46ch] text-[16px] leading-relaxed text-steel-300">
          Embed an iframe, verify a signature, credit your user. That is the whole surface area.
        </p>

        <ul class="mt-9 space-y-4">
          <li class="flex gap-3.5">
            <span class="num mt-0.5 shrink-0 text-[11px] text-amber-500">01</span>
            <div><p class="text-[14px] font-medium">Dedupe on <span class="num text-amber-400">trans_id</span></p><p class="mt-0.5 text-[13px] leading-relaxed text-steel-400">We retry failed callbacks. You will see the same transaction twice.</p></div>
          </li>
          <li class="flex gap-3.5">
            <span class="num mt-0.5 shrink-0 text-[11px] text-amber-500">02</span>
            <div><p class="text-[14px] font-medium">Handle negative amounts</p><p class="mt-0.5 text-[13px] leading-relaxed text-steel-400">A reversal arrives as a negative payout on the original transaction ID.</p></div>
          </li>
          <li class="flex gap-3.5">
            <span class="num mt-0.5 shrink-0 text-[11px] text-amber-500">03</span>
            <div><p class="text-[14px] font-medium">Return a 2xx</p><p class="mt-0.5 text-[13px] leading-relaxed text-steel-400">Anything else and we retry on the schedule above.</p></div>
          </li>
        </ul>

        <a href="/docs/introduction" class="group mt-9 inline-flex items-center gap-2 text-[14px] font-medium text-amber-500 transition-colors hover:text-amber-400">
          Read the full documentation
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="transition-transform group-hover:translate-x-0.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
      </div>

      <div class="overflow-hidden rounded-2xl border border-steel-700 bg-obsidian-950">
        <div class="flex items-center gap-1 border-b border-steel-700 px-2 py-2" role="tablist" aria-label="Language">
          <template x-for="l in ['php','node','python','curl']" :key="l">
            <button @click="lang = l" role="tab" :aria-selected="lang===l"
                    class="num rounded-md px-3 py-1.5 text-[12px] transition-colors"
                    :class="lang===l ? 'bg-steel-800 text-chalk' : 'text-steel-400 hover:text-steel-300'"
                    x-text="l"></button>
          </template>
          <span class="num ml-auto pr-2 text-[11px] text-steel-400">callback handler</span>
        </div>

        <div class="overflow-x-auto p-5">
          <pre x-show="lang==='php'" class="num text-[11.5px] leading-[1.75] text-steel-300"><code><span class="italic text-steel-300">// Verify, dedupe, credit. That is the whole handler.</span>
$expected = hash(<span class="text-gain">'sha256'</span>,
    $_GET[<span class="text-gain">'trans_id'</span>] . $_GET[<span class="text-gain">'payout'</span>] . ADVAULT_SECRET);

<span class="text-amber-400">if</span> (! hash_equals($expected, $_GET[<span class="text-gain">'sig'</span>])) {
    http_response_code(<span class="text-amber-400">403</span>);
    <span class="text-amber-400">exit</span>;
}

<span class="italic text-steel-300">// We retry — you WILL see this twice.</span>
<span class="text-amber-400">if</span> (Transaction::where(<span class="text-gain">'trans_id'</span>, $_GET[<span class="text-gain">'trans_id'</span>])->exists()) {
    <span class="text-amber-400">exit</span>(<span class="text-gain">'ok'</span>);
}

<span class="italic text-steel-300">// Negative on a reversal. Handle it.</span>
$user->increment(<span class="text-gain">'coins'</span>, (<span class="text-amber-400">int</span>) $_GET[<span class="text-gain">'amount'</span>]);

<span class="text-amber-400">echo</span> <span class="text-gain">'ok'</span>;</code></pre>

          <pre x-show="lang==='node'" x-cloak class="num text-[11.5px] leading-[1.75] text-steel-300"><code><span class="text-amber-400">import</span> { createHash, timingSafeEqual } <span class="text-amber-400">from</span> <span class="text-gain">'node:crypto'</span>;

app.get(<span class="text-gain">'/advault/callback'</span>, <span class="text-amber-400">async</span> (req, res) => {
  <span class="text-amber-400">const</span> { trans_id, payout, amount, sig } = req.query;

  <span class="text-amber-400">const</span> expected = createHash(<span class="text-gain">'sha256'</span>)
    .update(trans_id + payout + process.env.ADVAULT_SECRET)
    .digest(<span class="text-gain">'hex'</span>);

  <span class="italic text-steel-300">// Constant time — a === comparison leaks the signature byte by byte.</span>
  <span class="text-amber-400">const</span> a = Buffer.from(expected, <span class="text-gain">'hex'</span>);
  <span class="text-amber-400">const</span> b = Buffer.from(String(sig ?? <span class="text-gain">''</span>), <span class="text-gain">'hex'</span>);

  <span class="text-amber-400">if</span> (a.length !== b.length || !timingSafeEqual(a, b)) <span class="text-amber-400">return</span> res.sendStatus(<span class="text-amber-400">403</span>);

  <span class="italic text-steel-300">// dedupe — retries are expected traffic</span>
  <span class="text-amber-400">if</span> (<span class="text-amber-400">await</span> Tx.exists({ trans_id })) <span class="text-amber-400">return</span> res.send(<span class="text-gain">'ok'</span>);

  <span class="text-amber-400">await</span> User.credit(req.query.user_id, <span class="text-amber-400">Number</span>(amount));
  res.send(<span class="text-gain">'ok'</span>);
});</code></pre>

          <pre x-show="lang==='python'" x-cloak class="num text-[11.5px] leading-[1.75] text-steel-300"><code><span class="text-amber-400">import</span> hashlib, hmac

<span class="text-amber-400">@@app</span>.get(<span class="text-gain">"/advault/callback"</span>)
<span class="text-amber-400">def</span> callback(trans_id, payout, amount, user_id, sig):
    expected = hashlib.sha256(
        (trans_id + payout + ADVAULT_SECRET).encode()
    ).hexdigest()

    <span class="text-amber-400">if not</span> hmac.compare_digest(expected, sig):
        <span class="text-amber-400">return</span> <span class="text-gain">""</span>, <span class="text-amber-400">403</span>

    <span class="italic text-steel-300"># retries are expected — dedupe</span>
    <span class="text-amber-400">if</span> Transaction.exists(trans_id):
        <span class="text-amber-400">return</span> <span class="text-gain">"ok"</span>

    <span class="italic text-steel-300"># amount is negative on a reversal</span>
    User.credit(user_id, <span class="text-amber-400">int</span>(amount))
    <span class="text-amber-400">return</span> <span class="text-gain">"ok"</span></code></pre>

          <pre x-show="lang==='curl'" x-cloak class="num text-[11.5px] leading-[1.75] text-steel-300"><code><span class="italic text-steel-300"># What we send you on a credit</span>
GET https://yoursite.com/advault/callback
  ?user_id=<span class="text-amber-400">USER_123</span>
  &amp;trans_id=<span class="text-amber-400">OFFER19624243-Gw2CpY</span>
  &amp;payout=<span class="text-amber-400">0.091000</span>
  &amp;amount=<span class="text-amber-400">91.00</span>
  &amp;country=<span class="text-amber-400">US</span>
  &amp;status=<span class="text-gain">credited</span>
  &amp;sig=<span class="text-amber-400">a3f9…</span>

<span class="italic text-steel-300"># …and on a reversal</span>
  &amp;payout=<span class="text-loss">-0.091000</span>
  &amp;amount=<span class="text-loss">-91.00</span>
  &amp;status=<span class="text-loss">reversed</span></code></pre>
        </div>
      </div>

    </div>
  </div>
</section>
