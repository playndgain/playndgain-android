{{-- In-page links are absolute (`/#terms`, not `#terms`). This footer renders on
     the docs, the legal pages and the contact form too, where a bare fragment
     resolves against the current path and goes nowhere. --}}
<footer class="border-t border-steel-700 bg-obsidian-900 pt-16 pb-10">
  <div class="mx-auto max-w-[1280px] px-6">
    <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-6 lg:gap-8">

      <div class="lg:col-span-2">
        <a href="{{ url('/') }}" class="inline-block">
          <x-brand-mark :size="24" />
        </a>
        <p class="mt-4 max-w-[38ch] text-[13.5px] leading-relaxed text-steel-400">
          An offerwall built for publishers who read their own logs.
        </p>
      </div>

      <div>
        <h3 class="mb-4 text-[13px] font-semibold">Product</h3>
        <ul class="space-y-2.5 text-[13.5px] text-steel-400">
          <li><a href="{{ url('/#wall') }}" class="transition-colors hover:text-chalk">Offerwall</a></li>
          <li><a href="{{ url('/#terms') }}" class="transition-colors hover:text-chalk">Payouts</a></li>
          <li><a href="{{ url('/docs/traffic-quality') }}" class="transition-colors hover:text-chalk">Fraud protection</a></li>
          <li><a href="{{ route('status') }}" class="transition-colors hover:text-chalk">Status</a></li>
        </ul>
      </div>

      <div>
        <h3 class="mb-4 text-[13px] font-semibold">Developers</h3>
        <ul class="space-y-2.5 text-[13.5px] text-steel-400">
          <li><a href="{{ url('/docs/quick-start') }}" class="transition-colors hover:text-chalk">Quick start</a></li>
          <li><a href="{{ url('/docs/macro-reference') }}" class="transition-colors hover:text-chalk">Macro reference</a></li>
          <li><a href="{{ url('/docs/chargebacks-reversals') }}" class="transition-colors hover:text-chalk">Chargebacks</a></li>
          <li><a href="{{ url('/docs/troubleshooting') }}" class="transition-colors hover:text-chalk">Troubleshooting</a></li>
        </ul>
      </div>

      <div>
        <h3 class="mb-4 text-[13px] font-semibold">Company</h3>
        <ul class="space-y-2.5 text-[13.5px] text-steel-400">
          <li><a href="{{ route('about') }}" class="transition-colors hover:text-chalk">About</a></li>
          <li><a href="{{ route('advertise') }}" class="transition-colors hover:text-chalk">Supply offers</a></li>
          <li><a href="{{ route('contact') }}" class="transition-colors hover:text-chalk">Contact</a></li>
        </ul>
      </div>

      <div>
        <h3 class="mb-4 text-[13px] font-semibold">Legal</h3>
        <ul class="space-y-2.5 text-[13.5px] text-steel-400">
          <li><a href="{{ route('legal', 'publisher-agreement') }}" class="transition-colors hover:text-chalk">Publisher agreement</a></li>
          <li><a href="{{ url('/docs/prohibited-traffic') }}" class="transition-colors hover:text-chalk">Traffic policy</a></li>
          <li><a href="{{ route('legal', 'privacy') }}" class="transition-colors hover:text-chalk">Privacy</a></li>
          <li><a href="{{ route('legal', 'terms') }}" class="transition-colors hover:text-chalk">Terms</a></li>
        </ul>
      </div>
    </div>

    <div class="mt-14 flex flex-col items-center justify-between gap-4 border-t border-steel-700 pt-7 sm:flex-row">
      <p class="num text-[12px] text-steel-400">&copy; {{ date('Y') }} {{ \App\Support\Identity::name() }}</p>
      <p class="num text-[12px] text-steel-600">Figures on this page are illustrative until launch.</p>
    </div>
  </div>
</footer>
