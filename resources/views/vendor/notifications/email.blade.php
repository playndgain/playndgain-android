{{-- The view behind every MailMessage the app does not write itself: Filament's
     verification, reset and 2FA mails, Spatie's backup reports. The subject
     becomes the headline in the ink band, so nothing opens with "Hello!" or
     "Whoops!"; a greeting a notification does set wins. The eyebrow is the
     date, or "Action needed" in rose when the level is error. The default
     "Regards" sign-off is dropped; a salutation a notification sets is kept. --}}
@php
    $title = ! empty($greeting) ? $greeting : ($subject ?? \App\Support\Identity::name());
    $tone = $level === 'error' ? 'danger' : 'default';
    $eyebrow = $level === 'error' ? 'Action needed' : now()->translatedFormat('j F Y');
    $color = match ($level) {
        'error' => 'error',
        default => 'primary',
    };
@endphp
<x-mail::message>
<x-slot:hero>
<x-mail::hero :eyebrow="$eyebrow" :title="$title" :tone="$tone" />
</x-slot:hero>
{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation, only when the notification wrote one. --}}
@if (! empty($salutation))
{{ $salutation }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If the \":actionText\" button does not work, copy this link into your browser:",
    ['actionText' => $actionText]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
