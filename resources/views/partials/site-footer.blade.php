@php
    $name = \App\Support\Identity::name();

    // Rendered only when a URL is set — an empty value hides the link rather
    // than shipping a dead one.
    $social = collect([
        'x' => 'X',
        'linkedin' => 'LinkedIn',
        'discord' => 'Discord',
        'instagram' => 'Instagram',
    ])->filter(fn (string $label, string $key): bool => filled(config("playndgain.social.{$key}")));

    $columns = \App\Support\LandingContent::footerColumns();
    // `?: null` matters: MessageBag::first() returns '' when the bag holds
    // nothing, and '' !== null is true — so every check below fired on every
    // render. The form drew an empty error paragraph, marked a valid input
    // aria-invalid, and never reached the success branch at all.
    $newsletterError = $errors->newsletter->first('email') ?: null;
@endphp

{{-- Paper, like the page's closing sections. In-page links are bare
     fragments on the landing page and `/#terms` on the docs, the legal pages
     and the contact form, where a bare fragment goes nowhere
     (LandingContent::section()). --}}
<footer class="on-paper border-t border-paper-200">
  <div class="shell grid grid-cols-2 gap-x-8 gap-y-12 pt-16 pb-12 md:grid-cols-3 lg:grid-cols-[1.6fr_repeat(3,1fr)]">

    <div class="col-span-2 md:col-span-3 lg:col-span-1">
      <a href="{{ url('/') }}" class="inline-block rounded-pill text-coal-900" aria-label="{{ $name }} — home">
        <x-brand-mark :size="24" />
      </a>
      <p class="mt-5 max-w-[30ch] text-[15px] leading-relaxed text-coal-600">
        The rewarded offerwall that shows its arithmetic.
      </p>

      @if ($social->isNotEmpty())
        <ul class="mt-6 flex flex-wrap gap-2" aria-label="Elsewhere">
          @foreach ($social as $key => $label)
            <li><a href="{{ config("playndgain.social.{$key}") }}" rel="noopener" target="_blank" class="pill pill-paper transition-colors duration-[var(--motion-fast)] hover:bg-paper-200">{{ $label }}</a></li>
          @endforeach
        </ul>
      @endif
    </div>

    @foreach ($columns as $heading => $items)
      <nav aria-label="{{ $heading }}">
        <p class="mb-4 text-[14px] font-bold text-coal-900">{{ $heading }}</p>
        <ul class="flex flex-col gap-3 text-[14.5px] text-coal-600">
          @foreach ($items as [$href, $label])
            <li><a href="{{ $href }}" class="transition-colors duration-[var(--motion-fast)] hover:text-coal-900">{{ $label }}</a></li>
          @endforeach
        </ul>
      </nav>
    @endforeach
  </div>

  <div class="shell">
    <div id="newsletter" class="grid scroll-mt-24 gap-8 border-t border-paper-200 py-10 lg:grid-cols-[1.2fr_1fr] lg:items-center">
      <div>
        <p class="h3 text-coal-900">Stay in the loop</p>
        <p class="mt-2 text-[15px] text-coal-600">Release notes and new demand partners. Roughly monthly.</p>
      </div>

      <div>
        {{-- A plain POST to this app — no script between the button and the row.
             The `company` field is a honeypot: hidden from people, filled by
             scripts, and a filled one is thanked and dropped. --}}
        <form method="POST" action="{{ route('newsletter.subscribe') }}" class="flex gap-2" data-newsletter>
          @csrf
          <label for="newsletter-email" class="sr-only">Email address</label>
          <input id="newsletter-email" type="email" name="email" required maxlength="190" autocomplete="email"
                 placeholder="you@company.com"
                 value="{{ $newsletterError !== null ? old('email') : '' }}"
                 @if ($newsletterError !== null) aria-invalid="true" aria-describedby="newsletter-error" @endif
                 class="min-w-0 flex-1 rounded-pill bg-paper-0 px-5 py-3.5 text-[15px] text-coal-900 shadow-[inset_0_0_0_1px_var(--color-paper-300)] placeholder:text-coal-400 focus:shadow-[inset_0_0_0_1px_var(--color-coal-400)]">
          <div class="hidden" aria-hidden="true">
            <label for="newsletter-company">Company</label>
            <input id="newsletter-company" type="text" name="company" tabindex="-1" autocomplete="off">
          </div>
          <button type="submit" class="btn btn-ink btn-sm" data-newsletter-button>Subscribe</button>
        </form>

        {{-- The bundle-blocked answer. site.js removes this the moment it takes
             over the form, so the toast is never a second copy of it. The mark
             is inlined rather than a sprite reference: #i-check lives in the
             landing page's own sprite and this footer is on every page. --}}
        @if ($newsletterError !== null)
          <p id="newsletter-error" data-newsletter-inline
             class="mt-3 flex items-start gap-2 text-[15px] font-semibold leading-snug text-amber-700">
            <svg class="mt-px size-[18px] flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M6 6l12 12M18 6L6 18"/>
            </svg>
            {{ $newsletterError }}
          </p>
        @elseif (session('newsletter'))
          <p role="status" data-newsletter-inline
             class="mt-3 flex items-start gap-2 text-[15px] font-semibold leading-snug text-amber-700">
            <svg class="mt-px size-[18px] flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M5 12.5l4.5 4.5L19 7"/>
            </svg>
            {{ session('newsletter') }}
          </p>
        @endif
      </div>
    </div>

    <div class="flex flex-wrap justify-between gap-x-6 gap-y-3 border-t border-paper-200 py-8 text-[14px] text-coal-600">
      <span class="num">&copy; {{ date('Y') }} {{ $name }}</span>
      <ul class="flex flex-wrap gap-x-6 gap-y-2">
        <li><a href="{{ route('legal', 'publisher-agreement') }}" class="transition-colors duration-[var(--motion-fast)] hover:text-coal-900">Publisher agreement</a></li>
        <li><a href="{{ url('/docs/traffic-quality') }}" class="transition-colors duration-[var(--motion-fast)] hover:text-coal-900">Traffic policy</a></li>
        <li><a href="{{ route('legal', 'privacy') }}" class="transition-colors duration-[var(--motion-fast)] hover:text-coal-900">Privacy</a></li>
        <li><a href="{{ route('legal', 'terms') }}" class="transition-colors duration-[var(--motion-fast)] hover:text-coal-900">Terms</a></li>
      </ul>
          </div>
  </div>
</footer>
