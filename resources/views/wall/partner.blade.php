@extends('wall.layout')

@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var \App\Models\Network|null $partner */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Network> $partners */
    /** @var string|null $src */
    $params = $context->params();
    $openQuery = $params === '' ? 'open=1' : $params.'&open=1';
    $framed = $src !== null;
@endphp

{{-- No @section('title'): the document title stays the wall's own name across
     every tab (docs/03 §2 spec §9). The <h1> is what names the active tab. --}}

@section('content')
<header class="wall-header">
    <div class="wall-shell">
        @include('wall.partials.topbar', ['active' => 'partners'])

        @if ($framed)
            <a class="offer-back" href="{{ route('wall.partners') }}?{{ $params }}">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M10 3 5 8l5 5"/>
                </svg>
                {{ __('wall.partner.back') }}
            </a>
        @else
            <div class="wall-header__row">
                <div class="wall-header__lede">
                    <h1 class="wall-header__title">{{ __('wall.tabs.partners') }}</h1>
                </div>
            </div>
        @endif

        {{-- Only when there is a choice to make, and only on the frame —
             the catalogue is the picker. --}}
        @if ($framed && $partners->count() > 1)
            <div class="wall-chips" role="group" aria-label="{{ __('wall.tabs.partners') }}">
                @foreach ($partners as $option)
                    <a class="wall-chip @if ($option->is($partner)) is-on @endif"
                       @if ($option->is($partner)) aria-current="page" @endif
                       href="{{ route('wall.partner', $option) }}?{{ $openQuery }}">{{ $option->name }}</a>
                @endforeach
            </div>
        @endif
    </div>
</header>

<main id="offers" class="wall-main">
    <div class="wall-shell">
        @if ($partner === null)
            <div class="wall-empty">
                <span class="wall-empty__icon" aria-hidden="true">
                    <svg><use href="#ic-grid"/></svg>
                </span>
                <h2>{{ __('wall.partner.empty.title') }}</h2>
                <p>{{ __('wall.partner.empty.body') }}</p>
                <a class="wall-button" href="{{ route('wall') }}?{{ $params }}">{{ __('wall.partner.empty.action') }}</a>
            </div>
        @elseif ($framed)
            {{-- The same permissions our own embed docs ask of publishers, for
                 the same reason: a partner's offers end in store links, and a
                 frame that cannot top-navigate converts at zero. --}}
            <iframe class="wall-partner__frame"
                    src="{{ $src }}"
                    title="{{ $partner->name }}"
                    sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-top-navigation-by-user-activation"
                    allow="clipboard-write"
                    referrerpolicy="no-referrer"></iframe>
        @else
            {{-- One card per partner. The href is our own frame, not theirs —
                 a new tab is a different product. --}}
            <div class="wall-launch-wrap">
                @foreach ($partners as $option)
                    <a class="wall-launch" href="{{ route('wall.partner', $option) }}?{{ $openQuery }}">
                        <span class="wall-launch__stage">
                            @if (filled($option->logo_path))
                                <img src="{{ asset($option->logo_path) }}" alt=""
                                     width="240" height="80"
                                     loading="lazy" decoding="async" onerror="this.remove()">
                            @else
                                <span class="wall-launch__letter" aria-hidden="true">{{ mb_substr($option->name, 0, 1) }}</span>
                            @endif
                        </span>

                        <span class="wall-launch__meta">
                            <span class="wall-launch__kicker">{{ __('wall.partner.kicker') }}</span>
                            <span class="wall-launch__name">{{ $option->name }}</span>
                            <span class="wall-launch__go">
                                {{ __('wall.partner.open') }}
                                <svg aria-hidden="true"><use href="#ic-chevron"/></svg>
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</main>

@include('wall.partials.nav', ['active' => 'partners'])
@include('wall.partials.menu')
@endsection
