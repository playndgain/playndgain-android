{{-- An ops alert. Everything here is admin-only: the network name in the
     subject line is fine because the alert list is the admin mailbox. --}}
<x-mail::message>
<x-slot:hero>
<x-mail::hero :eyebrow="$pages ? 'Page' : 'Alert'" :title="$key" />
</x-slot:hero>
{{ $headline }}

@if ($pages)
<x-mail::panel>
docs/11 §7 rates this signal as a page. {{ $platform }} has no paging channel yet. This email is the whole notification, so treat it as one.
</x-mail::panel>
@endif

@if ($subject !== null || $context !== [])
<x-mail::facts :rows="($subject !== null ? ['Subject' => $subject] : []) + $context" />
@endif

The application log holds every occurrence. This email is sent once per cooldown window.
</x-mail::message>
