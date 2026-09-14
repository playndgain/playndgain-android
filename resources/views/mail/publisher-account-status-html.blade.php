@php
    $pubId = (string) $publisher->pub_id;
    $dashboard = \App\Support\Identity::url().'/dashboard';
    $support = \App\Support\Identity::supportEmail();

    [$eyebrow, $title, $body, $buttonLabel, $buttonUrl] = match ($status) {
        \App\Enums\PublisherStatus::Approved => [
            'Account',
            'Your account is approved',
            'You can use the full publisher panel now. Apps you create still go through review before the wall serves live offers.',
            'Open dashboard',
            $dashboard,
        ],
        \App\Enums\PublisherStatus::Suspended => [
            'Account',
            'Your account is suspended',
            'The panel is read-only and withdrawals are blocked until this is resolved.',
            'Contact support',
            'mailto:'.$support,
        ],
        \App\Enums\PublisherStatus::Banned => [
            'Account',
            'Your application was not approved',
            'This login cannot access the publisher panel.',
            'Contact support',
            'mailto:'.$support,
        ],
        default => [
            'Account',
            'Account status update',
            'Sign in to the publisher panel for the latest state on publisher #'.$pubId.'.',
            'Open dashboard',
            $dashboard,
        ],
    };
@endphp

<x-mail::message>
<x-slot:hero>
<x-mail::hero :eyebrow="$eyebrow" :title="$title" />
</x-slot:hero>
{{ $body }}

@if (filled($reason))
<x-mail::panel>
{{ $reason }}
</x-mail::panel>
@endif

<x-mail::button :url="$buttonUrl">
{{ $buttonLabel }}
</x-mail::button>
</x-mail::message>
