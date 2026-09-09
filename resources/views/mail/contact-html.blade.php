<x-mail::message>
<x-slot:hero>
<x-mail::hero eyebrow="Contact form" title="{{ $topic }}" />
</x-slot:hero>
<x-mail::facts :rows="['From' => $senderName, 'Email' => $senderEmail]" />

<x-mail::panel>
{!! nl2br(e($body)) !!}
</x-mail::panel>

<x-mail::button :url="'mailto:'.$senderEmail">
Reply to {{ $senderName }}
</x-mail::button>
</x-mail::message>
