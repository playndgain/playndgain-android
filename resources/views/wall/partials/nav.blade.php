@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var string $active */
    $params = $context->params();
@endphp

{{-- The app-nav: under 640px it replaces the header's tab strip (one nav per
     breakpoint, never both), so the wall reads like the app it is. Plain
     anchors — with JS off every destination still works; only the menu
     trigger is enhancement-gated with x-cloak. --}}
<nav class="wall-nav" aria-label="{{ __('wall.tabs.label') }}" x-data>
    <a class="wall-nav__item @if ($active === 'offers') is-on @endif"
       @if ($active === 'offers') aria-current="page" @endif
       href="{{ route('wall') }}?{{ $params }}">
        <svg class="wall-nav__icon" aria-hidden="true"><use href="#ic-home"/></svg>
        <span>{{ __('wall.tabs.offers') }}</span>
    </a>

    <a class="wall-nav__item @if ($active === 'history') is-on @endif"
       @if ($active === 'history') aria-current="page" @endif
       href="{{ route('wall.history') }}?{{ $params }}">
        <svg class="wall-nav__icon" aria-hidden="true"><use href="#ic-clock"/></svg>
        <span>{{ __('wall.tabs.history') }}</span>
    </a>

    <a class="wall-nav__item @if ($active === 'partners') is-on @endif"
       @if ($active === 'partners') aria-current="page" @endif
       href="{{ route('wall.partners') }}?{{ $params }}">
        <svg class="wall-nav__icon" aria-hidden="true"><use href="#ic-grid"/></svg>
        <span>{{ __('wall.tabs.partners') }}</span>
    </a>

    <button type="button" class="wall-nav__item" data-menu x-cloak>
        <svg class="wall-nav__icon" aria-hidden="true"><use href="#ic-menu"/></svg>
        <span>{{ __('wall.menu.label') }}</span>
    </button>
</nav>
