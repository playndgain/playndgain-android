@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var string $active */

    $active ??= 'offers';

    // The publisher passes the user's balance in their own currency as a
    // string (`?balance=`). Integer strings get grouped; anything else —
    // "21k", "21.5" — is the publisher's own formatting and passes through.
    $balance = $context->balance !== null && ctype_digit($context->balance)
        ? number_format((int) $context->balance)
        : $context->balance;
@endphp

{{-- The app bar: the platform lockup on the start edge, section pills and
     the balance on the end. `x-data` with no expression: the menu trigger
     is hidden with x-cloak until Alpine can wire it. The click wiring
     itself is plain JS on the shell — no Alpine magic in an attribute,
     which the wall's no-`$` test forbids. --}}
<div class="wall-topbar" x-data>
    <span class="wall-topbar__brand">
        <x-brand-mark :size="26" />
    </span>

    <span class="wall-topbar__end">
        @include('wall.partials.tabs', ['active' => $active])

        <span class="wall-topbar__cluster">
            @if ($balance !== null)
                {{-- Rendered only when the publisher sends it: an app that never
                     passes `balance` gets no pill, exactly as before. --}}
                <span class="wall-balance" title="{{ $context->app->currency_name }}">
                    <svg class="wall-balance__icon" aria-hidden="true"><use href="#ic-coin"/></svg>
                    <span class="num" dir="ltr">{{ $balance }}</span>
                    <span class="wall-sr">{{ $context->app->currency_name }}</span>
                </span>
            @endif

            <button type="button" class="wall-menu-button" data-menu x-cloak
                    aria-label="{{ __('wall.menu.label') }}">
                <svg class="wall-menu-button__icon" aria-hidden="true"><use href="#ic-menu"/></svg>
            </button>
        </span>
    </span>
</div>
