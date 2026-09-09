@php
    // The mechanism, briefly, after the benefit above it. "Filtered on every
    // render" lives here and only here now — it had a card of its own in the
    // features section as well, and this is where the capture beside it shows
    // the filtering happening.
    $points = [
        ['i-palette', 'Your brand, not ours', 'Colours, radius, currency name and conversion rate, per placement.'],
        ['i-filter', 'Filtered on every render', 'Country, device and OS are checked per request, so nobody is shown an offer they cannot finish.'],
        ['i-code', 'One catalogue, one payout', 'However many demand sources sit behind the wall, you read one list and get paid once.'],
    ];
@endphp

{{-- The product: a real capture of the publisher dashboard, large, inside one
     ink card, with the three things a publisher asks first beside it. This
     shot is the first one past the fold, so it loads eagerly at low priority
     rather than waiting to be scrolled near. --}}
<section class="on-paper pt-4 pb-[var(--section)]" aria-labelledby="product-title">
  <div class="shell grid grid-cols-1 items-center gap-10 lg:grid-cols-[4.5fr_7.5fr] lg:gap-14">
    <div data-reveal>
      <p class="eyebrow text-amber-700">One integration</p>
      <h2 id="product-title" class="display-2 mt-4 text-coal-900">One wall. Your brand.</h2>
      <p class="lead mt-5 text-coal-600">Your players see your colours and your currency. You see one catalogue, one dashboard and one payout.</p>

      <ul class="mt-8 space-y-5">
        @foreach ($points as [$icon, $title, $body])
          <li class="flex gap-4">
            <span class="mt-0.5 grid size-10 shrink-0 place-items-center rounded-[12px] bg-amber-400/15 text-amber-700">
              <svg class="size-5" aria-hidden="true"><use href="#{{ $icon }}"/></svg>
            </span>
            <div>
              <p class="text-[16px] font-semibold text-coal-900">{{ $title }}</p>
              <p class="mt-1 text-[15px] leading-relaxed text-coal-600">{{ $body }}</p>
            </div>
          </li>
        @endforeach
      </ul>
    </div>

    <div class="relative" data-reveal style="--i: 1">
      <div class="card-gradient relative overflow-hidden p-3 sm:p-5 lg:p-6">
        <div class="browser">
          <div class="browser-bar" aria-hidden="true"><i></i><i></i><i></i><span class="browser-url"></span></div>
          <div class="shot">
            <img src="/img/landing/dashboard.webp" srcset="/img/landing/dashboard-1x.webp 1440w, /img/landing/dashboard.webp 2160w"
                 sizes="(min-width: 1024px) 760px, 92vw" width="2160" height="1350" loading="eager" fetchpriority="low" decoding="async"
                 alt="The publisher dashboard: available, pending and lifetime balances beside seven days of clicks and conversions">
          </div>
        </div>
      </div>

      {{-- Two pills used to float over this capture: a credited figure and a
           row of theme swatches whose colours were four raw hex values with no
           token behind them. The capture is a real panel and it reads better
           without anything standing on it — so what is left is the one thing
           the picture cannot say for itself, which is whose account it is. --}}
      {{-- coal-600, not coal-400: this is a caption a reader is meant to read,
           and coal-400 is decorative only (site-tokens.css). 7.3:1 on paper-50. --}}
      <p class="mt-4 text-[13px] font-medium text-coal-600">{{ \App\Support\LandingContent::demoNote() }}</p>
    </div>
  </div>
</section>
