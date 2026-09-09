{{-- Publisher-only pitch: one full-width panel with the wall hanging out of
     its foot — framed as the iframe it is, with the publisher's own URL in
     the bar and the one-line snippet beside it. Advertisers are not a product
     surface — demand arrives through partner networks, never a self-serve
     advertiser side. --}}
<section class="on-paper pb-[var(--section)]" aria-labelledby="solutions-title">
  <div class="shell">
    <div class="max-w-[640px]" data-reveal>
      <p class="eyebrow text-amber-700">For publishers</p>
      <h2 id="solutions-title" class="display-2 mt-4 text-coal-900">Built for the people who bring the players.</h2>
    </div>

    <article id="publishers" class="card-gradient card-lift mt-10 flex scroll-mt-24 flex-col overflow-hidden text-fog-50 lg:grid lg:grid-cols-[1.05fr_0.95fr] lg:items-end" data-reveal>
      <div class="p-7 pb-0 sm:p-9 sm:pb-0 lg:p-10 lg:pb-10">
        <span class="grid size-12 place-items-center rounded-[14px] bg-amber-400 text-on-amber">
          <svg class="size-6" aria-hidden="true"><use href="#i-players"/></svg>
        </span>
        <p class="label mt-7 text-fog-500">Your wall, your brand</p>
        <h3 class="display-3 mt-3">Monetise every player.</h3>
        <p class="lead mt-4 max-w-[44ch] text-fog-300">Embed the wall, keep your brand, and get paid monthly for every verified offer your players complete. No SDK, no app-store review.</p>
        <a href="/dashboard/register" class="btn btn-white mt-7">Start earning <svg class="size-4" aria-hidden="true"><use href="#i-arrow"/></svg></a>
      </div>

      <div class="relative mt-6 h-[340px] sm:h-[380px] lg:mt-0 lg:h-full lg:min-h-[400px]" aria-hidden="true">
        <div class="absolute right-5 top-8 w-[280px] sm:right-10 sm:w-[320px] lg:right-8 lg:top-10" data-reveal style="--i: 1">
          <div class="embed">
            <div class="embed-bar"><i></i><i></i><i></i><span class="embed-url">yourgame.app/<b>rewards</b></span></div>
            <div class="shot">
              <img src="/img/landing/wall-light.webp" srcset="/img/landing/wall-light-1x.webp 390w, /img/landing/wall-light.webp 780w"
                   sizes="320px" width="780" height="1560" loading="lazy" decoding="async" alt="">
            </div>
          </div>
        </div>

        {{-- The snippet that puts it there, arriving after the frame. --}}
        <div class="seq contents">
          <div class="embed-tag absolute bottom-8 left-4 hidden sm:inline-flex lg:left-6"><b>&lt;iframe</b><span>&nbsp;src="</span>…/wall?appid=<b>YOUR_APP_ID</b><span>"</span><b>&gt;</b></div>
        </div>
      </div>
    </article>
  </div>
</section>
