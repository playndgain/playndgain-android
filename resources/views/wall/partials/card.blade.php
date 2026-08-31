@php
    /** @var array<string, mixed> $offer */
    /** @var string $params */
    /** @var string $currency */
    /** @var bool $rail */
    /** @var bool $preview */
    /** @var bool $compact */

    $rail = $rail ?? false;
    $preview = $preview ?? false;
    $compact = $compact ?? false;

    $iconSrc = $offer['icon_src'] ?? null;

    if (! is_string($iconSrc) || $iconSrc === '') {
        $iconSrc = ($offer['has_icon'] ?? false) ? '/img/offer/'.$offer['id'] : null;
    }

    // Test inventory says only that. Anything else can legitimately carry both
    // tags. The rail is the featured treatment already, so its cards drop the
    // chips — the reference strip is icon, name, note, reward, and nothing else.
    $tags = $offer['is_test']
        ? [[__('wall.test_badge'), true]]
        : array_values(array_filter([
            $offer['is_featured'] ? [__('wall.featured'), true] : null,
            ($offer['goal_count'] ?? 0) > 1
                ? [trans_choice('wall.steps', $offer['goal_count'], ['count' => $offer['goal_count']]), false]
                : ($offer['is_multi_reward'] ? [__('wall.multi_reward'), false] : null),
        ]));
@endphp

{{-- One card component, two containers: the grid and the featured rail render
     this same file. A second card template for the rail drifts — the first
     change to the reward pill lands in one of them and not the other.

     A link to the offer's own page, not a div with a click handler: focusable,
     middle-clickable, and readable by a screen reader as what it is. The dialog
     is an interception of this same URL. --}}
@if ($preview)
<div class="wall-card @if ($rail) wall-card--rail @endif">
@else
<a class="wall-card @if ($rail) wall-card--rail @endif"
   href="{{ route('wall.offer', $offer['id']) }}?{{ $params }}"
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
        <span class="wall-card__head">
            <span class="wall-card__name">{{ $offer['name'] }}</span>
            @if (! $rail && ! $compact && $tags !== [])
                <span class="wall-tags">
                    @foreach ($tags as [$label, $accent])
                        <span class="wall-tag @if ($accent) wall-tag--accent @endif">{{ $label }}</span>
                    @endforeach
                </span>
            @endif
        </span>

        @if (filled($offer['instructions']))
            <span class="wall-card__note">{{ $offer['instructions'] }}</span>
        @endif

        @if (! $rail && ! $compact)
            @include('wall.partials.facts', [
                'devices' => $offer['devices'] ?? [],
                'people' => (int) ($offer['people'] ?? 0),
            ])
        @endif
    </span>

    {{-- The whole card is still the link; the pill is the affordance drawn,
         never a second control. --}}
    <span class="wall-card__end">
        <span class="wall-reward wall-reward--cta">
            @if (($offer['goal_count'] ?? 0) > 1)
                <span class="wall-reward__upto">{{ __('wall.up_to') }}</span>
            @endif
            <span class="wall-reward__row">
                <span class="num wall-reward__value">+{{ $offer['reward_label'] }}</span>
                <span class="wall-reward__currency">{{ $currency }}</span>
            </span>
        </span>
    </span>
@if ($preview)
</div>
@else
</a>
@endif
