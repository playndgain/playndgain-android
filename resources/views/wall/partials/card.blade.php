@php
    /** @var array<string, mixed> $offer */
    /** @var string $params */
    /** @var string $currency */
    /** @var bool $rail     Kept for callers; the featured edge now travels with the offer. */
    /** @var bool $preview */
    /** @var bool $compact */
    /** @var bool $bare     Figure only — no sign, no currency label. The landing mocks. */

    $rail = $rail ?? false;
    $preview = $preview ?? false;
    $compact = $compact ?? false;
    $bare = $bare ?? false;

    // The featured edge travels with the offer rather than with the container
    // it is rendered in. A pinned row keeps its mark wherever it lands, which
    // is what makes a filtered list still honest about what is pinned.
    $lead = ($offer['is_featured'] ?? false) && ! ($offer['is_test'] ?? false);

    $iconSrc = $offer['icon_src'] ?? null;

    if (! is_string($iconSrc) || $iconSrc === '') {
        $iconSrc = ($offer['has_icon'] ?? false) ? '/img/offer/'.$offer['id'] : null;
    }

    // Everything that is not the name, the instruction or the figure, on one
    // line, joined by a separator. It used to be two pill badges plus a device
    // strip plus a headcount, all competing with the reward for the same row;
    // a row can carry one loud thing and the figure is it.
    //
    // Test inventory says only that. Anything else can legitimately carry both.
    $meta = $offer['is_test']
        ? [__('wall.test_badge')]
        : array_values(array_filter([
            $offer['is_featured'] ? __('wall.featured') : null,
            ($offer['goal_count'] ?? 0) > 1
                ? trans_choice('wall.steps', $offer['goal_count'], ['count' => $offer['goal_count']])
                : ($offer['is_multi_reward'] ? __('wall.multi_reward') : null),
        ]));
@endphp

{{-- One card component, three containers: the grid, the publisher's live
     preview and the landing mock render this same file. A second template
     drifts — the first change to the figure lands in one of them and not the
     others.

     A link to the offer's own page, not a div with a click handler: focusable,
     middle-clickable, and readable by a screen reader as what it is. The dialog
     is an interception of this same URL. --}}
@php
    $cardClass = 'wall-card';
    $cardClass .= $rail ? ' wall-card--rail' : ($lead ? ' wall-card--lead' : '');
@endphp
@if ($preview)
<div class="{{ $cardClass }}">
@else
<a class="{{ $cardClass }}"
   href="{{ route('wall.offer', $offer['id']) }}?{{ $params }}"
   data-identity-link
   @click="open">
@endif

    <span class="wall-tile">
        <span class="wall-tile__letter" aria-hidden="true">{{ mb_substr((string) $offer['name'], 0, 1) }}</span>
        @if (is_string($iconSrc))
            {{-- Proxied on the live wall (docs/03 §6). The landing mock points
                 at local store artwork so the tile is still a real icon. --}}
            <img class="wall-tile__img" src="{{ $iconSrc }}"
                 alt="{{ $offer['name'] }}" width="56" height="56"
                 loading="lazy" decoding="async" onerror="this.remove()">
        @endif
    </span>

    <span class="wall-card__body">
        <span class="wall-card__name">{{ $offer['name'] }}</span>

        @if (filled($offer['instructions']))
            <span class="wall-card__note">{{ $offer['instructions'] }}</span>
        @endif

        @if (! $rail && ! $compact)
            <span class="wall-card__meta">
                @foreach ($meta as $label)
                    <span class="wall-card__meta-item">{{ $label }}</span>
                @endforeach

                @include('wall.partials.facts', [
                    'devices' => $offer['devices'] ?? [],
                    'people' => (int) ($offer['people'] ?? 0),
                ])
            </span>
        @endif
    </span>

    {{-- The ledger column. Fixed width so every figure on the list shares one
         inline-end edge and the user compares by glance rather than by
         reading. No chevron beside it: the whole row is the link, so an arrow
         is decoration standing where the only number belongs. --}}
    <span class="wall-card__end">
        <span class="wall-reward">
            @if (($offer['goal_count'] ?? 0) > 1)
                <span class="wall-reward__upto">{{ __('wall.up_to') }}</span>
            @endif

            @if ($bare)
                {{-- The design board draws the mock's figure bare — "640", not
                     "+640 COINS" — because the card's own header already names
                     the currency. Only the landing asks for this; the live wall
                     and the publisher preview keep the sign and the label. --}}
                <span class="num wall-reward__value">{{ $offer['reward_label'] }}</span>
            @else
                <span class="num wall-reward__value">+{{ $offer['reward_label'] }}</span>
                <span class="wall-reward__currency">{{ $currency }}</span>
            @endif
        </span>
    </span>
@if ($preview)
</div>
@else
</a>
@endif
