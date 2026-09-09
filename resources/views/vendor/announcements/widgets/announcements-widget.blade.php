{{--
    The vendor widget, repainted. Same data, same dismiss call, same polling;
    what changed is the chrome: no inline hex, no light-dark() pairs, no info
    blue. Severity is carried by a hairline on the leading edge and the icon,
    in the panel's three status tones — `info` reads as the neutral fog, the
    way the theme already maps Filament's info ramp. Styles live in
    resources/css/filament/theme.css under "Announcements".

    Hidden entirely when there is nothing to say: an empty "no announcements"
    card at the top of every dashboard is noise.
--}}
@php
    use Marcelodelgado\Announcements\Enums\AnnouncementType;

    $pollingInterval = $this->getPollingInterval();
    $announcements = $this->getAnnouncements();
@endphp

<x-filament-widgets::widget
    :attributes="
        (new \Illuminate\View\ComponentAttributeBag)
            ->merge([
                'wire:poll.' . $pollingInterval => $pollingInterval ? true : null,
            ], escape: false)
            ->class([
                'fi-wi-announcements',
                'fi-wi-announcements-empty' => $announcements->isEmpty(),
            ])
    "
>
    @if ($announcements->isNotEmpty())
        <ul class="pg-ann-list" aria-label="Announcements">
            @foreach ($announcements as $announcement)
                @php
                    $tone = match ($announcement->type) {
                        AnnouncementType::Danger => 'danger',
                        AnnouncementType::Warning => 'warning',
                        AnnouncementType::Success => 'success',
                        AnnouncementType::Info => 'neutral',
                    };

                    $icon = match ($announcement->type) {
                        AnnouncementType::Danger => 'heroicon-o-exclamation-circle',
                        AnnouncementType::Warning => 'heroicon-o-exclamation-triangle',
                        AnnouncementType::Success => 'heroicon-o-check-circle',
                        AnnouncementType::Info => 'heroicon-o-information-circle',
                    };
                @endphp

                <li
                    wire:key="announcement-{{ $announcement->id }}"
                    class="pg-ann pg-ann-{{ $tone }}"
                >
                    <x-filament::icon :icon="$icon" class="pg-ann-icon" />

                    <div class="pg-ann-body">
                        <h3 class="pg-ann-title">{{ $announcement->title }}</h3>
                        <p class="pg-ann-text">{{ $announcement->body }}</p>
                    </div>

                    @if ($announcement->is_dismissible)
                        <x-filament::icon-button
                            color="gray"
                            icon="heroicon-o-x-mark"
                            :label="__('announcements::widget.dismiss')"
                            wire:click="dismiss({{ $announcement->id }})"
                            class="pg-ann-dismiss"
                        />
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-widgets::widget>
