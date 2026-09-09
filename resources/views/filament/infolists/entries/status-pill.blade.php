{{-- The table's status pill, in an infolist tile — one component, one look. --}}
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

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if (! $ready)
        <p class="fi-in-placeholder">—</p>
    @else
        <span class="ad-status ad-status--{{ $status->value }}">
            <span class="ad-status__mark" aria-hidden="true">
                {{ generate_icon_html($status->getIcon(), size: IconSize::Small) }}
            </span>
            <span class="ad-status__label">{{ $status->getLabel() }}</span>
        </span>
    @endif
</x-dynamic-component>
