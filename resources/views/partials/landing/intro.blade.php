{{-- The page-open intro: the mark on the hero's own ground while the
     stylesheet, Sora and the hero capture arrive, then a flight into the
     header's mark as the curtain lifts and the hero enters behind it.

     Three gates, all in the layout's head script: JavaScript present, motion
     allowed, and a first visit in this tab (html.hero-seen skips it; ?intro
     replays it). Without any of them this block is display:none and the page
     is what the server sent. site.js never holds it past its cap, and the head
     script's failsafe drops it if the bundle never arrives. Nothing in here is
     focusable and the whole block is hidden from assistive technology: it is a
     picture of the brand, not content.

     The lockup is the header's, scaled: the same tile geometry, the gap and
     the wordmark in the same proportions (app.css, .site-intro-mark), so the
     flight at the end lands on the header's mark exactly. The letters are
     wrapped one by one so they can rise in turn once the face has loaded. --}}
<div class="site-intro" data-intro aria-hidden="true">
  <div class="site-intro-curtain"></div>

  <span class="brand-mark site-intro-mark" data-intro-mark>
    <svg class="brand-mark-tile site-intro-tile" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id="site-intro-gradient" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse">
          <stop offset="0" stop-color="#FFC554"/>
          <stop offset="1" stop-color="#F28C1A"/>
        </linearGradient>
      </defs>
      <rect width="64" height="64" rx="16" fill="url(#site-intro-gradient)"/>
      <rect class="site-intro-stem" x="15.5" y="13.5" width="11" height="38" rx="1.5" fill="#1A1000"/>
      <path class="site-intro-bowl" d="M21 19H33a11 11 0 0 1 0 22H21" fill="none" stroke="#1A1000" stroke-width="11" pathLength="100"/>
    </svg>
    <span class="brand-mark-word site-intro-word">@foreach (mb_str_split(\App\Support\Identity::name()) as $i => $letter)<i style="--i: {{ $i }}">{{ $letter }}</i>@endforeach</span>
  </span>

  <span class="site-intro-line"><i data-intro-bar></i></span>
</div>
