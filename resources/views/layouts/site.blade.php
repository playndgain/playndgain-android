<!doctype html>
<html lang="en"@hasSection('intro') data-intro @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', \App\Support\Identity::name())</title>
<meta name="description" content="@yield('description')">
<meta name="theme-color" content="#0A0B14">

{{-- Classes before first paint. `js` lets the stylesheet hold scroll
     entrances back until the observer releases them — without it nothing is
     ever hidden. `hero-seen` skips the hero entrance and the intro on a later
     visit in this tab (`?intro` replays both). `intro` switches the page-open
     intro on: only where the page carries one (data-intro), only on a first
     visit, only when motion is allowed. Two failsafes for a bundle that never
     arrives (stale HTML after a rebuild): held entrances are uncovered at 2 s
     and the intro is dropped at 3.5 s, so the page can stay neither blank nor
     covered. --}}
<script>(function(d){d.classList.add('js');var replay=/[?&]intro(?:=|&|$)/.test(location.search),seen=false;try{seen=!!sessionStorage.getItem('pg-hero');if(!seen)sessionStorage.setItem('pg-hero','1')}catch(e){}if(seen&&!replay)d.classList.add('hero-seen');if(d.hasAttribute('data-intro')&&!d.classList.contains('hero-seen')&&matchMedia('(prefers-reduced-motion: no-preference)').matches)d.classList.add('intro');setTimeout(function(){if(d.classList.contains('site-ready'))return;d.classList.add('site-ready');d.querySelectorAll('[data-reveal],[data-reveal-group]').forEach(function(el){el.classList.add('is-in')})},2000);setTimeout(function(){d.classList.remove('intro')},3500)})(document.documentElement)</script>

{{-- Social cards read the same title and description the tab does. The image
     is the 1200×630 card scripts/capture-landing.mjs renders. --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ \App\Support\Identity::name() }}">
<meta property="og:title" content="@yield('title', \App\Support\Identity::name())">
<meta property="og:description" content="@yield('description')">
<meta property="og:url" content="{{ \App\Support\Identity::url().request()->getRequestUri() }}">
<meta property="og:image" content="{{ \App\Support\Identity::url() }}/img/og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{{ \App\Support\Identity::url() }}/img/og.png">

@include('partials.favicons')

{{-- Self-hosted, one origin, one face: Sora's Latin file is the only font on
     the critical path; latin-ext arrives by unicode-range when a page needs
     it. `crossorigin` is required even same-origin or the browser fetches the
     font twice. --}}
<link rel="preload" as="font" type="font/woff2" href="/fonts/sora-latin-wght-normal.woff2" crossorigin>

@stack('head')

@vite(['resources/css/app.css', 'resources/js/site.js'])
</head>

<body class="bg-ink-950 font-sans text-fog-50 antialiased">

<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-pill focus:bg-amber-400 focus:px-4 focus:py-2 focus:text-[14px] focus:font-semibold focus:text-on-amber">Skip to content</a>

@yield('intro')

@include('partials.site-header')

@yield('content')

@include('partials.site-footer')

{{-- Empty until site.js puts a card in it. In the markup rather than created
     on demand so a screen reader is already listening on the region when one
     arrives; with the bundle blocked it stays empty and costs nothing. --}}
<div class="toast-wrap" data-toasts role="status" aria-live="polite"></div>

</body>
</html>
