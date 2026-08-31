@php
    /** @var list<string> $devices */
    /** @var int $people */

    // Whitelisted against the glyphs that exist, for the reason the detail
    // view filters country codes: `offers.devices` is a JSON column fed by nine
    // network catalogues and by an admin typing into a tag field, and an
    // unknown value here becomes a `<use>` pointing at nothing — an empty box
    // on the card. `other` is deliberately absent: an offer that runs on
    // "other" is one the wall never serves anyway.
    //
    // Empty targeting is "any device" (OfferFeed::listAllows). The reference
    // card draws the three platforms there rather than leaving a popularity
    // count sitting on its own.
    $known = ['android', 'ios', 'desktop'];
    $glyphs = array_values(array_intersect(
        array_map(fn (string $device): string => mb_strtolower($device), $devices),
        $known,
    ));
    $open = $glyphs === [];
    $glyphs = $open ? $known : $glyphs;
@endphp

{{-- The two facts a card could not otherwise answer: what it runs on, and
     whether anybody is actually doing it. Shared by the card and the detail
     screen, so the screen the user lands on confirms the one they tapped. --}}
@if ($glyphs !== [] || $people > 0)
    <span class="wall-facts">
        @if ($glyphs !== [])
            <span class="wall-facts__item">
                {{-- The icons are aria-hidden and the names are read instead:
                     three glyphs announced as three images is noise, and the
                     device an offer runs on is the kind of fact a screen
                     reader user is filtering on. --}}
                <span class="wall-sr">{{ $open ? __('wall.offer.any_device') : __('wall.offer.devices').': '.implode(', ', array_map(fn (string $device): string => __("wall.device.{$device}"), $glyphs)) }}</span>

                @foreach ($glyphs as $device)
                    <svg class="wall-facts__icon" aria-hidden="true"><use href="#ic-{{ $device }}"/></svg>
                @endforeach
            </span>
        @endif

        {{-- Omitted entirely at zero. "0 people" is worse than silence on a
             card the wall wants clicked. --}}
        @if ($people > 0)
            <span class="wall-facts__item">
                <svg class="wall-facts__icon" aria-hidden="true"><use href="#ic-person"/></svg>
                <span class="wall-sr">{{ trans_choice('wall.people', $people) }}</span>
                <span class="num">+{{ number_format($people) }}</span>
            </span>
        @endif
    </span>
@endif
