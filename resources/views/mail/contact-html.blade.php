<x-mail::message>
# Contact form

**Topic:** {{ $topic }}

**From:** {{ $senderName }} ({{ $senderEmail }})

<x-mail::panel>
{!! nl2br(e($body)) !!}
</x-mail::panel>
</x-mail::message>
