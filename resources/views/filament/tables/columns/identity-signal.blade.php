{{-- Same amber attention pill as the signup-queue match cell. A match is
     attention, not a money-direction loss, so it is never rose. --}}
@php
    use App\Enums\IdentitySignal;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use function Filament\Support\generate_icon_html;

    $state = $getState();
    $signal = $state instanceof IdentitySignal ? $state : IdentitySignal::tryFrom((string) $state);

    $label = match ($signal) {
        IdentitySignal::Ds => 'Device',
        IdentitySignal::Session => 'Session',
        IdentitySignal::Ip => 'IP',
        default => '—',
    };

    $icon = match ($signal) {
        IdentitySignal::Ds => Heroicon::OutlinedCpuChip,
        IdentitySignal::Session => Heroicon::OutlinedKey,
        IdentitySignal::Ip => Heroicon::OutlinedGlobeAlt,
        default => Heroicon::OutlinedQuestionMarkCircle,
    };
@endphp

<div class="ad-status-cell">
    <span class="ad-status ad-status--hit">
        <span class="ad-status__mark" aria-hidden="true">
            {{ generate_icon_html($icon, size: IconSize::Small) }}
        </span>
        <span class="ad-status__label">{{ $label }}</span>
    </span>
</div>
