{{-- Geo targeting as overlapping flags. An empty list is worldwide, which is
     what the placeholder says — the same word the rest of the table uses. --}}
@php
    $codes = collect($getState() ?? [])
        ->filter(fn (mixed $code): bool => is_string($code) && preg_match('/^[A-Za-z]{2}$/', $code) === 1)
        ->map(fn (string $code): string => strtoupper($code))
        ->unique()
        ->values();

    $shown = $codes->take(3);
    $rest = $codes->count() - $shown->count();

    // Codes are what the network sent; names are what a human reads. `intl` is
    // in the server requirements, but the tooltip falls back to the code rather
    // than fataling if a box is missing it.
    $tooltip = $codes
        ->map(fn (string $code): string => extension_loaded('intl')
            ? (\Locale::getDisplayRegion('-'.$code, 'en') ?: $code)
            : $code)
        ->implode(', ');
@endphp

@if ($codes->isEmpty())
    <p class="fi-ta-placeholder">all</p>
@else
    {{-- Three flags, then a count. The full list is the tooltip's job: a cell
         wide enough for thirty countries is a cell nobody can scan. --}}
    <div class="ad-stack"
         x-tooltip="{ content: @js($tooltip), theme: $store.theme }"
         aria-label="{{ $tooltip }}">
        @foreach ($shown as $code)
            {{-- Plain <img>, not flag-icons' `.fi-xx` classes: those collide
                 with Filament's own `fi-` prefix, and `.fi-no` is its
                 notifications overlay. --}}
            <img class="pg-flag"
                 src="{{ asset('flags/'.strtolower($code).'.svg') }}"
                 width="22"
                 height="16"
                 alt="{{ $code }}"
                 loading="lazy">
        @endforeach

        @if ($rest > 0)
            <span class="ad-more">+{{ $rest }}</span>
        @endif
    </div>
@endif
