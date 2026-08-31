<!doctype html>
<html lang="en" class="scroll-smooth">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', \App\Support\Identity::name())</title>
<meta name="description" content="@yield('description')">

{{-- Files, not a data URI: the panels and any request that never reaches this
     layout fall back to /favicon.ico, which was a zero-byte placeholder.
     Regenerate all three with `php scripts/make-favicons.php`. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

{{-- Self-hosted, one origin: no third-party request on a site that talks about
     privacy (docs/09 §4). Preload only the two Latin faces — latin-ext is
     fetched by unicode-range, on the rare page that needs it. `crossorigin` is
     required even same-origin or the browser fetches each font twice. --}}
<link rel="preload" as="font" type="font/woff2" href="/fonts/archivo-latin.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="/fonts/geist-mono-latin.woff2" crossorigin>

@stack('head')

@vite(['resources/css/app.css', 'resources/js/site.js'])
</head>

<body class="bg-obsidian-950 text-chalk font-sans antialiased selection:bg-amber-500 selection:text-obsidian-950">

@include('partials.icon-sprite')

<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-amber-500 focus:text-obsidian-950 focus:px-4 focus:py-2 focus:rounded-lg focus:font-medium">Skip to content</a>

@include('partials.site-header')

@yield('content')

@include('partials.site-footer')

</body>
</html>
