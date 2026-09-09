@props([
    /** Pixel size of the tile. The wordmark scales with it. */
    'size' => 20,
    /** Set false for the tile alone — the favicon-shaped surfaces, tight bars. */
    'wordmark' => true,
    /** A second line under the wordmark — the panels name themselves here
        ("Publisher", "Admin"). Null everywhere else. */
    'label' => null,
    /** 'accent' paints the label amber (the admin panel); 'muted' is the default. */
    'labelTone' => 'muted',
])

@php
    // One gradient definition per instance: a page renders the mark more than
    // once, and two <linearGradient>s with one id paint the second tile black.
    $gradient = 'pgm-'.substr(md5(uniqid('', true)), 0, 8);
@endphp

{{-- The mark, in one place.

     A rounded amber tile carrying a bold geometric "P", beside the wordmark.
     The letter is two primitives — a stem and a stroked bowl — not text: the
     favicon, the mail header and the wall's loading splash all render it, and
     none of them can wait for a font. scripts/make-favicons.php draws the same
     geometry from the same numbers, so the icon set cannot drift from here.

     Styling comes from brand-mark.css, imported by every bundle.

     Which panel you are in IS written here, and only here: the panels pass
     `label`, which sets a second line under the wordmark inside the lockup —
     one unit, no extra width, instead of a badge competing with the logo.

     On the offerwall it appears exactly once, in the footer attribution: the
     wall wears the publisher's brand and the design copy promises their
     players never see a logo that is not theirs. --}}

<span {{ $attributes->merge(['class' => 'brand-mark']) }}>
    <svg class="brand-mark-tile" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id="{{ $gradient }}" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#FFC554"/>
                <stop offset="1" stop-color="#F28C1A"/>
            </linearGradient>
        </defs>
        <rect width="64" height="64" rx="16" fill="url(#{{ $gradient }})"/>
        <rect x="15.5" y="13.5" width="11" height="38" rx="1.5" fill="#1A1000"/>
        <path d="M21 19H33a11 11 0 0 1 0 22H21" fill="none" stroke="#1A1000" stroke-width="11"/>
    </svg>

    @if ($wordmark && $label)
        <span class="brand-mark-text">
            <span class="brand-mark-word" style="font-size: {{ round($size * 0.62, 1) }}px">{{ \App\Support\Identity::name() }}</span>
            <span @class(['brand-mark-label', 'brand-mark-label-accent' => $labelTone === 'accent'])>{{ $label }}</span>
        </span>
    @elseif ($wordmark)
        <span class="brand-mark-word" style="font-size: {{ round($size * 0.72, 1) }}px">{{ \App\Support\Identity::name() }}</span>
    @endif
</span>
