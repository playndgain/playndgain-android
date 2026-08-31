@php
    /** @var \App\Services\Wall\WallContext $context */

    // The toggle rides the existing `?theme=` per-load override. params()
    // already carries the current value, so replacing the key rather than
    // appending a second one keeps the URL clean.
    parse_str($context->params(), $menuFields);
    $menuFields['theme'] = $context->isDark() ? 'light' : 'dark';
    $themeUrl = route('wall').'?'.http_build_query($menuFields);
@endphp

{{-- Only destinations that exist: the appearance toggle, and the operator's
     own legal pages. A menu entry that leads nowhere is worse than no entry.
     Same native-<dialog> contract as the offer sheet: focus trap, Esc and the
     backdrop come free; the wiring lives on the shell in wall.js. --}}
<dialog class="wall-menu" id="wall-menu" aria-label="{{ __('wall.menu.label') }}">
    <div class="wall-menu__head">
        <span class="wall-menu__title">{{ __('wall.menu.label') }}</span>

        <button type="button" class="offer-dialog__close" data-close aria-label="{{ __('wall.offer.close') }}">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <path d="m1.5 1.5 11 11M12.5 1.5l-11 11"/>
            </svg>
        </button>
    </div>

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
