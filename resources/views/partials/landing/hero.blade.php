{{-- The hero: the claim, two actions, and the product itself — the real wall
     on a phone, captured by scripts/capture-landing.mjs. It enters once per
     tab session (data-enter, staggered by --i); the orb behind the phone is
     the ambient motion and is still for anyone who asked for less. The phone
     image is the page's largest contentful paint, so it is preloaded from
     here at high priority. --}}
@push('head')
<link rel="preload" as="image" href="/img/landing/wall-dark.webp"
      imagesrcset="/img/landing/wall-dark-1x.webp 390w, /img/landing/wall-dark.webp 780w"
      imagesizes="(min-width: 1280px) 330px, (min-width: 640px) 300px, 260px" fetchpriority="high">
@endpush

<section class="hero-bg on-ink hero-enter" aria-labelledby="hero-title">
  <div class="shell relative grid grid-cols-1 items-center gap-14 pt-16 pb-32 lg:grid-cols-[1.05fr_1fr] lg:gap-16 lg:pt-24 lg:pb-44">
    <div class="relative z-10 max-w-[620px]">
      <p class="pill pill-ink" data-enter style="--i: 0">
        <span class="pill-dot" aria-hidden="true"></span>
        Rewarded offerwall · one iframe
      </p>

      <h1 id="hero-title" class="display-1 mt-8" data-enter style="--i: 1">Turn playtime into payouts.</h1>

      <p class="lead mt-7 max-w-[44ch] text-fog-300" data-enter style="--i: 2">
        Drop the {{ $name }} offerwall into any app or game. You are paid for every verified action.
      </p>

      <div class="mt-10 flex flex-wrap gap-3" data-enter style="--i: 3">
        <a href="/dashboard/register" class="btn btn-primary">Start earning</a>
        <a href="{{ route('contact') }}?topic=publisher" class="btn btn-ghost-ink">Talk to us</a>
      </div>

      <ul class="mt-10 flex flex-wrap gap-x-8 gap-y-3 text-[14px] font-medium text-fog-300" data-enter style="--i: 4">
        @foreach (['Live in a day', 'Paid monthly', '$'.$terms['min_payout'].' minimum'] as $proof)
          <li class="flex items-center gap-2">
            <svg class="size-4 text-amber-400" aria-hidden="true"><use href="#i-check"/></svg>{{ $proof }}
          </li>
        @endforeach
      </ul>
    </div>

    <div class="relative mx-auto w-full max-w-[560px] lg:max-w-none">
      <div class="hero-orb -left-[12%] -top-[6%] h-[112%] w-[124%]" aria-hidden="true"></div>

      {{-- Nothing floats over the phone any more. Three pills used to: a
           credited reward, a postback round-trip and an eligibility pair. Two
           of the three carried a figure from nowhere — "+640 Coins" belonged to
           an offer that does not exist — and all three stood in front of the
           thing they were annotating, which is the catalogue itself: the offer
           art, the titles and the rewards. The capture is the argument, and it
           makes it better uncovered. --}}
      <div class="phone-stage relative mx-auto w-[260px] sm:w-[300px] xl:w-[330px]">
        <div class="phone" data-enter="phone">
          <div class="shot">
            <img class="phone-screen" src="/img/landing/wall-dark.webp"
                 srcset="/img/landing/wall-dark-1x.webp 390w, /img/landing/wall-dark.webp 780w"
                 sizes="(min-width: 1280px) 330px, (min-width: 640px) 300px, 260px"
                 width="780" height="1560" fetchpriority="high" decoding="async"
                 alt="The {{ $name }} offerwall on a phone: featured offers and a catalogue of games, surveys and installs, priced in the app's own currency">
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
