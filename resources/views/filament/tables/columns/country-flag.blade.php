{{-- Single ISO-2 as a flag. The name is the tooltip — "SE" is a puzzle,
     the same reason the offers table never prints codes. `intl` is in the
     server requirements; without it the code is still true. --}}
@php
    $raw = $getState();
    $code = is_string($raw) ? strtoupper(trim($raw)) : '';
    $valid = preg_match('/^[A-Z]{2}$/', $code) === 1;

    $name = match (true) {
        ! $valid => '',
        $code === 'XX' => 'Other',
        extension_loaded('intl') => (\Locale::getDisplayRegion('-'.$code, 'en') ?: $code),
        default => $code,
    };
@endphp

@if (! $valid)
    <p class="fi-ta-placeholder pg-flag-cell">—</p>
@elseif ($code === 'XX')
    <div class="pg-flag-cell"
         x-tooltip="{ content: @js($name), theme: $store.theme }"
         aria-label="{{ $name }}">
        <span class="pg-flag-fallback">{{ $code }}</span>
    </div>
@else
    {{-- Plain <img>, not flag-icons' `.fi-xx` classes: those collide
         with Filament's own `fi-` prefix. Same rule as geo-stack.
         `pg-flag` — EasyList generic-hides the ad-prefixed name. --}}
    <div class="pg-flag-cell"
         x-tooltip="{ content: @js($name), theme: $store.theme }"
         aria-label="{{ $name }}">
        <img class="pg-flag pg-flag--solo"
             src="{{ asset('flags/'.strtolower($code).'.svg') }}"
             width="25"
             height="18"
             alt="{{ $name }}"
             loading="lazy">
    </div>
@endif
