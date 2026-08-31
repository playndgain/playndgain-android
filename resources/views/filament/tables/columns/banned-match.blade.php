{{-- Banned collision, not a Filament danger badge. Red is money direction
     (design.md §2); a match is attention, so it shares the amber status pill. --}}
@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use function Filament\Support\generate_icon_html;

    $hits = $getState();
    $hits = is_array($hits)
        ? array_values(array_filter($hits, fn (mixed $hit): bool => is_string($hit) && $hit !== ''))
        : [];

    $hasEmail = in_array('email', $hits, true);
    $hasIp = in_array('IP', $hits, true);

    $kind = $hasEmail || $hasIp ? 'hit' : 'clear';

    $label = match (true) {
        $hasEmail && $hasIp => 'Email + IP',
        $hasEmail => 'Email',
        $hasIp => 'IP',
        default => 'Clear',
    };

    $icon = match (true) {
        $hasEmail && $hasIp => Heroicon::OutlinedFlag,
        $hasEmail => Heroicon::OutlinedEnvelope,
        $hasIp => Heroicon::OutlinedGlobeAlt,
        default => Heroicon::OutlinedCheck,
    };
@endphp

<div class="ad-status-cell">
    <span class="ad-status ad-status--{{ $kind }}">
        <span class="ad-status__mark" aria-hidden="true">
            {{ generate_icon_html($icon, size: IconSize::Small) }}
        </span>
        <span class="ad-status__label">{{ $label }}</span>
    </span>
</div>
