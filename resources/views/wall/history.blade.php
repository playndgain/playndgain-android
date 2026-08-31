@extends('wall.layout')

@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var list<array<string, mixed>> $rows */
    $currency = $context->app->currency_name;
@endphp

{{-- No @section('title'): the document title stays the wall's own name across
     every tab. The <h1> is what names the active one. --}}

@section('content')
<header class="wall-header">
    <div class="wall-shell">
        @include('wall.partials.topbar', ['active' => 'history'])

        <div class="wall-header__row">
            <div class="wall-header__lede">
                <h1 class="wall-header__title">{{ __('wall.tabs.history') }}</h1>
            </div>
        </div>
    </div>
</header>

<main class="wall-main">
    <div class="wall-shell">
        @if ($rows === [])
            {{-- Named for the cause, like the wall's own two. Nothing tried yet
                 is not an error. --}}
            <div class="wall-empty">
                <span class="wall-empty__icon" aria-hidden="true">
                    <svg><use href="#ic-clock"/></svg>
                </span>
                <h2>{{ __('wall.history.empty.title') }}</h2>
                <p>{{ __('wall.history.empty.body') }}</p>
                <a class="wall-button" href="{{ route('wall') }}?{{ $context->params() }}">{{ __('wall.history.empty.action') }}</a>
            </div>
        @else
            <ul class="wall-history">
                @foreach ($rows as $row)
                    <li class="wall-history__row">
                        <span class="wall-tile wall-tile--sm">
                            <span class="wall-tile__letter" aria-hidden="true">{{ mb_substr($row['name'], 0, 1) }}</span>
                            @if ($row['has_icon'])
                                <img class="wall-tile__img" src="/img/offer/{{ $row['id'] }}"
                                     alt="" width="40" height="40"
                                     loading="lazy" decoding="async" onerror="this.remove()">
                            @endif
                        </span>

                        <span class="wall-history__body">
                            <span class="wall-history__name">{{ $row['name'] }}</span>

                            <span class="wall-history__meta">
                                {{-- Only a partner row names one. Ours came
                                     from our own catalogue and the offer name
                                     is the whole answer. --}}
                                @if ($row['partner'] !== null)
                                    <span class="wall-history__partner">{{ $row['partner'] }}</span>
                                @endif

                                {{-- ISO, and `ltr` inside the Arabic wall: a
                                     date is a figure, and a figure reads the
                                     same way in both directions. --}}
                                <span class="num" dir="ltr">{{ $row['at']?->format('Y-m-d') }}</span>

                                {{-- Text, never colour. Green and red mean money
                                     direction and nothing else on any AdVault
                                     surface (design.md §2), and a wall of green
                                     "Credited" pills would spend the one signal
                                     the product reserves. --}}
                                <span class="wall-history__status">{{ __('wall.history.status.'.$row['status']) }}</span>
                            </span>

                            @if ($row['reason'] !== null)
                                <span class="wall-history__reason">{{ $row['reason'] }}</span>
                            @endif
                        </span>

                        {{-- The credited amount is the green thing, because it
                             is money — and it is the sum of every goal that
                             cleared on this click, not the first one loaded. --}}
                        @if ($row['reward'] !== null)
                            <span class="wall-history__reward">
                                <span class="num" dir="ltr">+{{ $row['reward'] }}</span>
                                <span class="wall-history__currency">{{ $currency }}</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</main>

@include('wall.partials.nav', ['active' => 'history'])
@include('wall.partials.menu')
@endsection
