@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var string $active */
    $params = $context->params();
    $hasPartners = \App\Services\Wall\PartnerWall::available($context->app);
@endphp

{{-- Offers and History always. Offerwall only when a partner wall is live —
     an empty third tab is a destination that exists to say nothing. --}}
<nav class="wall-tabs" aria-label="{{ __('wall.tabs.label') }}">
    <a class="wall-tab @if ($active === 'offers') is-on @endif"
       @if ($active === 'offers') aria-current="page" @endif
       href="{{ route('wall') }}?{{ $params }}">
        <svg class="wall-tab__icon" aria-hidden="true"><use href="#ic-spark"/></svg>
        {{ __('wall.tabs.offers') }}
    </a>

    <a class="wall-tab @if ($active === 'history') is-on @endif"
       @if ($active === 'history') aria-current="page" @endif
       href="{{ route('wall.history') }}?{{ $params }}">
        <svg class="wall-tab__icon" aria-hidden="true"><use href="#ic-clock"/></svg>
        {{ __('wall.tabs.history') }}
    </a>

    @if ($hasPartners)
        <a class="wall-tab @if ($active === 'partners') is-on @endif"
           @if ($active === 'partners') aria-current="page" @endif
           href="{{ route('wall.partners') }}?{{ $params }}">
            <svg class="wall-tab__icon" aria-hidden="true"><use href="#ic-grid"/></svg>
            {{ __('wall.tabs.partners') }}
        </a>
    @endif
</nav>
