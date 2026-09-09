{{-- The page-open intro and the motion script for the panels' auth pages —
     rendered at SIMPLE_LAYOUT_START by both panels, so it exists on exactly
     the pages a visitor sees before they belong to a panel (login, register,
     password reset, verification) and never inside a dashboard.

     The gate runs where it is parsed, before the intro block below it and
     before anything of the page has been painted: JavaScript present (`js`),
     a first visit in this tab (`hero-seen` skips it, `?intro` replays it —
     the same key as the landing, so a visitor arriving from the site is not
     shown the brand twice), motion allowed. The failsafe drops the intro at
     3.5 s if auth.js never arrives. `vt` marks a page that arrived through a
     view transition (the note is left by the page before it, in auth.js), so
     it does not play its entrance on top of the crossfade. Markup and stylesheet are the landing's
     own (partials/landing/intro, site-intro.css via the theme).

     The view-transition rule lives here rather than in the theme so only the
     auth pages opt in: moving between them crossfades, with the brand panel
     held still and the card morphing (theme.css); a successful sign-in still
     cuts straight to the dashboard, which does not carry the rule. --}}
<script>(function(d){d.classList.add('js');var replay=/[?&]intro(?:=|&|$)/.test(location.search),seen=false;try{seen=!!sessionStorage.getItem('pg-hero');if(!seen)sessionStorage.setItem('pg-hero','1')}catch(e){}if(seen&&!replay)d.classList.add('hero-seen');try{if(sessionStorage.getItem('pg-vt')){d.classList.add('vt');sessionStorage.removeItem('pg-vt')}}catch(e){}if(!d.classList.contains('hero-seen')&&matchMedia('(prefers-reduced-motion: no-preference)').matches)d.classList.add('intro');setTimeout(function(){d.classList.remove('intro')},3500)})(document.documentElement)</script>
<style>@view-transition { navigation: auto; }</style>

@include('partials.landing.intro')

@vite('resources/js/auth.js')
