{{-- Status as a mark + label, not a Filament success/danger badge.
     Green and red are money direction (design.md §2); these tones are
     amber for attention and steel/slate for state. --}}
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
