@extends('wall.layout')

@section('title', $offer->name)

@section('content')
{{-- The standalone page: what a user gets with JS off, in a new tab, or from a
     shared link. The wall's dialog fetches the same URL with `fragment=1` and
     injects the article below on its own. --}}
<header class="wall-header wall-header--slim">
    <div class="wall-shell">
        <a class="offer-back" href="{{ route('wall') }}?{{ $context->params() }}">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M10 3 5 8l5 5"/>
            </svg>
            {{ __('wall.offer.back') }}
        </a>
    </div>
</header>

<main class="wall-main">
    <div class="wall-shell offer-shell">
        @include('wall.partials.offer')
    </div>
</main>
@endsection
