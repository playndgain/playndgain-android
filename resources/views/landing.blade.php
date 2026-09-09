@extends('layouts.site')

@section('title', \App\Support\Identity::name().' — Turn playtime into payouts')
@section('description', 'Drop the '.\App\Support\Identity::name().' offerwall into any app or game. Players complete offers from the networks you already know; you are paid for every verified action — signed callbacks, an append-only ledger, monthly payouts, terms stated once.')

{{-- The page-open intro (partials/landing/intro). Declared as a section so
     the layout can mark <html> with data-intro — the head script switches the
     intro on only where a page carries one. --}}
@section('intro')
  @include('partials.landing.intro')
@endsection

@section('content')
@php
    // Real figures only. LandingController leaves out anything it cannot
    // defend, and each section states what the product does in that slot
    // instead of showing a number from nowhere. The terms fall back to the
    // configured defaults so the page renders before a migration has run.
    $stats = $stats ?? [];
    $terms = array_merge(['hold_days' => '30', 'min_payout' => '20'], $terms ?? []);
    $snippet = $snippet ?? ['html' => '', 'lines' => 0];
    $name = \App\Support\Identity::name();
    $categories = \App\Support\LandingContent::demandCategories();
    $nodes = \App\Support\LandingContent::orbitNodes();
@endphp

{{-- Sections run in bands of one surface: ink, paper, ink, paper. Where the
     surface changes, a .seam (app.css) lays the incoming surface over the
     outgoing one as a sheet with a rounded top edge; with scroll-driven
     animations the outgoing band's content settles back as the sheet slides
     over it. The seam must directly follow the band it closes. --}}
<main id="main">
  @include('partials.landing.icons')
  @include('partials.landing.hero')
  @include('partials.landing.metrics')
  <div class="seam seam-to-paper" data-seam="1" aria-hidden="true"></div>
  @include('partials.landing.inventory')
  @include('partials.landing.product')
  @include('partials.landing.solutions')
  <div class="seam seam-to-ink" data-seam="2" aria-hidden="true"></div>
  @include('partials.landing.how')
  @include('partials.landing.features')
  <div class="seam seam-to-paper" data-seam="3" aria-hidden="true"></div>
  @include('partials.landing.results')
  @include('partials.landing.cta')
</main>
@endsection
