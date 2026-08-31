@props([
    /** Pixel size of the glyph. The wordmark is a fixed 16px beside it. */
    'size' => 26,
    /** Set false for the glyph alone — favicon-shaped surfaces, tight bars. */
    'wordmark' => true,
])

{{-- The mark, in one place.

     It used to be inline SVG in the header, again in the footer with a
     different stroke, and a third time as a data-URI favicon. Three copies of a
     logo drift, and the one nobody looks at is the one that ends up wrong.

     Flat amber rather than the header's old gradient: a gradient needs an `id`,
     and the same `id` rendered twice on one page (header and footer) is a
     collision the second one loses. The favicon has to be flat anyway, so flat
     everywhere is also the only version that matches itself.

     Styling comes from `.brand-mark` in tokens.css, not from utility classes —
     the panels build from their own Tailwind entry and never saw utilities
     written in this directory.

     Which panel you are in is NOT written here. The topbar badge says that,
     once, on the right-hand side of the bar.

     It IS on the offerwall, in two places and only two: the loading splash and
     a muted footer line. That surface is otherwise the publisher's brand, so
     the mark stays quiet and never competes with their palette — but it keeps
     its own colours, because a logo recoloured per app is not the logo. Note
     that docs/09's landing copy still promises users see no logo but the
     publisher's; that claim and this component now disagree. --}}

<span {{ $attributes->merge(['class' => 'brand-mark']) }}>
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 26 26" fill="none"
         aria-hidden="true">
        <rect x="1" y="1" width="24" height="24" rx="7" stroke="#262B33" stroke-width="1.5"/>
        <path d="M8 7.5L13 18.5L18 7.5" stroke="#F5A524" stroke-width="2.4"
              stroke-linecap="round" stroke-linejoin="round"/>
    </svg>

    @if ($wordmark)
        <span class="brand-mark-word">{{ \App\Support\Identity::name() }}</span>
    @endif
</span>
