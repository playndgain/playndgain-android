@extends('layouts.site')

@section('title', \App\Support\Identity::name().' — Monetize your users. Audit every cent.')
@section('description', 'Aggregated offers from a dozen ad networks, delivered through one hosted offerwall. Signed callbacks, chargeback protection, NET-30 payouts.')

@push('head')
{{-- The hero wallpaper is the LCP element. Preload matches the <img> srcset
     exactly or the browser fetches a second copy. --}}
<link rel="preload" as="image" href="/img/hero-vault.webp" imagesrcset="/img/hero-vault-sm.webp 960w, /img/hero-vault.webp 1920w" imagesizes="100vw" fetchpriority="high">
@endpush

@section('content')
@php
    // Defaults keep the page whole under the placeholder `Route::view` in
    // routes/web.php. LandingController overrides them with real counts once the
    // route is switched over; every figure it cannot defend stays out of it.
    $stats = array_merge([
        'offers' => 1284,
        'countries' => 134,
        'networks' => 6,
        'fraud_rules' => 17,
        'postback_ms' => 94,
        'avg_payout' => 2.79,
    ], $stats ?? []);
@endphp

@include('partials.landing.trace-rail')

<main id="main">
  @include('partials.landing.hero')
  @include('partials.landing.metrics')
  @include('partials.landing.how')
  @include('partials.landing.wall')
  @include('partials.landing.terms')
  @include('partials.landing.features')
  @include('partials.landing.integrate')
  @include('partials.landing.faq')
  @include('partials.landing.cta')
</main>
@endsection
