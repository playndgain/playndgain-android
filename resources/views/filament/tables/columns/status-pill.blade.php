{{-- Status as a mark + label. The tones follow the panel's success /
     warning / danger ramps (theme.css .ad-status--*): green settled,
     amber waiting, rose refused, mist for the rest. --}}
@php
    use Filament\Support\Contracts\HasIcon;
    use Filament\Support\Contracts\HasLabel;
    use Filament\Support\Enums\IconSize;
    use function Filament\Support\generate_icon_html;

    $status = $getState();
    $ready = $status instanceof \BackedEnum
        && $status instanceof HasLabel
        && $status instanceof HasIcon;
@endphp

@if (! $ready)
    <p class="fi-ta-placeholder ad-status-cell">—</p>
@else
    <div class="ad-status-cell">
        <span class="ad-status ad-status--{{ $status->value }}">
            <span class="ad-status__mark" aria-hidden="true">
                {{ generate_icon_html($status->getIcon(), size: IconSize::Small) }}
            </span>
            <span class="ad-status__label">{{ $status->getLabel() }}</span>
        </span>
    </div>
@endif
