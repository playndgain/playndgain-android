{{-- ISO-2 as a flag beside its name, inline in text. Same <img> rule as
     tables/columns/country-flag: flag-icons' `.fi-xx` classes collide with
     Filament's `fi-` prefix, so the SVGs are served as plain images.
     "XX" (unknown geo) has no flag and falls back to a code chip. --}}
@php
    $code = strtoupper(trim($code));
    $hasFlag = preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX';
@endphp
<span class="pg-country">
    @if ($hasFlag)
        <img class="pg-flag pg-flag--solo" src="{{ asset('flags/'.strtolower($code).'.svg') }}" width="25" height="18" alt="" loading="lazy">
    @else
        <span class="pg-flag-fallback" aria-hidden="true">{{ $code }}</span>
    @endif
    <span>{{ $label }}</span>
</span>
