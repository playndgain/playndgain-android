@php
    $pubId = (string) $publisher->pub_id;
    $dashboard = \App\Support\Identity::url().'/dashboard';
    $support = \App\Support\Identity::supportEmail();

    [$title, $body] = match ($status) {
        \App\Enums\PublisherStatus::Approved => [
            'Your account is approved',
            'You can use the full publisher panel now. Apps you create still go through review before the wall serves live offers.',
        ],
        \App\Enums\PublisherStatus::Suspended => [
            'Your account is suspended',
            'The panel is read-only and withdrawals are blocked until this is resolved. Contact '.$support.'.',
        ],
        \App\Enums\PublisherStatus::Banned => [
            'Your application was not approved',
            'This login cannot access the publisher panel. Contact '.$support.' if you believe this is a mistake.',
        ],
        default => [
            'Account status update',
            'Sign in to the publisher panel for the latest state on publisher #'.$pubId.'.',
        ],
    };
@endphp
{!! $title !!}

{!! $body !!}

@if (filled($reason))
{!! $reason !!}
@endif

{!! $dashboard !!}
