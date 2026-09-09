@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var string $active */

    $active ??= 'offers';
    $params = $context->params();
    $hasPartners = \App\Services\Wall\PartnerWall::available($context->app);

    // The toggle rides the existing `?theme=` per-load override. params()
    // already carries the current value, so replacing the key rather than
    // appending a second one keeps the URL clean.
    parse_str($params, $menuFields);
    $menuFields['theme'] = $context->isDark() ? 'light' : 'dark';
    $themeUrl = route('wall').'?'.http_build_query($menuFields);
@endphp

{{-- Side sheet: the same destinations as the strip, then appearance and the
     operator's legal pages. A menu entry that leads nowhere is worse than
     no entry. Native <dialog> for the focus trap, Esc and the backdrop;
     enter and leave are the same present/dismiss path as the offer sheet. --}}
<dialog class="wall-menu" id="wall-menu" aria-label="{{ __('wall.menu.label') }}">
    <div class="wall-menu__head">
        <span class="wall-menu__brand">
            <span class="wall-menu__mark" aria-hidden="true">{{ mb_substr($context->app->name, 0, 1) }}</span>
            <span class="wall-menu__lockup">
                <span class="wall-menu__name">{{ $context->app->name }}</span>
                <span class="wall-menu__title">{{ __('wall.earn', ['currency' => $context->app->currency_name]) }}</span>
            </span>
        </span>

        <button type="button" class="offer-dialog__close" data-close aria-label="{{ __('wall.offer.close') }}">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <path d="m1.5 1.5 11 11M12.5 1.5l-11 11"/>
            </svg>
        </button>
    </div>

    <ul class="wall-menu__nav">
        <li>
            <a class="wall-menu__item @if ($active === 'offers') is-on @endif"
               @if ($active === 'offers') aria-current="page" @endif
               href="{{ route('wall') }}?{{ $params }}">
                <svg class="wall-menu__icon" aria-hidden="true"><use href="#ic-home"/></svg>
                <span>{{ __('wall.tabs.offers') }}</span>
            </a>
        </li>
        <li>
            <a class="wall-menu__item @if ($active === 'history') is-on @endif"
               @if ($active === 'history') aria-current="page" @endif
               href="{{ route('wall.history') }}?{{ $params }}">
                <svg class="wall-menu__icon" aria-hidden="true"><use href="#ic-clock"/></svg>
                <span>{{ __('wall.tabs.history') }}</span>
            </a>
        </li>
        @if ($hasPartners)
            <li>
                <a class="wall-menu__item @if ($active === 'partners') is-on @endif"
                   @if ($active === 'partners') aria-current="page" @endif
                   href="{{ route('wall.partners') }}?{{ $params }}">
                    <svg class="wall-menu__icon" aria-hidden="true"><use href="#ic-grid"/></svg>
                    <span>{{ __('wall.tabs.partners') }}</span>
                </a>
            </li>
        @endif
    </ul>

    <ul class="wall-menu__list">
        <li>
            <a class="wall-menu__item" href="{{ $themeUrl }}">
                <svg class="wall-menu__icon" aria-hidden="true"><use href="#ic-{{ $context->isDark() ? 'sun' : 'moon' }}"/></svg>
                <span>{{ __($context->isDark() ? 'wall.menu.light' : 'wall.menu.dark') }}</span>
            </a>
        </li>
        <li>
            {{-- Off the iframe on purpose: legal is a page of its own, and the
                 publisher's frame is not where a reader finishes it. --}}
            <a class="wall-menu__item" href="{{ route('legal', 'privacy') }}" target="_blank" rel="noopener">
                <svg class="wall-menu__icon" aria-hidden="true"><use href="#ic-shield"/></svg>
                <span>{{ __('wall.menu.privacy') }}</span>
            </a>
        </li>
        <li>
            <a class="wall-menu__item" href="{{ route('legal', 'terms') }}" target="_blank" rel="noopener">
                <svg class="wall-menu__icon" aria-hidden="true"><use href="#ic-doc"/></svg>
                <span>{{ __('wall.menu.terms') }}</span>
            </a>
        </li>
    </ul>
</dialog>
