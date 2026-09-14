@php
    $appId = (string) $app->app_id;
    $appName = (string) $app->name;
    $appsUrl = \App\Support\Identity::url().'/dashboard/apps/'.$app->getRouteKey().'/edit';
    $support = \App\Support\Identity::supportEmail();

    [$eyebrow, $title, $body, $buttonLabel, $buttonUrl] = match ($status) {
        \App\Enums\AppStatus::Active => [
            'App',
            "App #{$appId} is live",
            "“{$appName}” is approved. The wall can serve live offers to your users.",
            'Open app settings',
            $appsUrl,
        ],
        \App\Enums\AppStatus::Rejected => [
            'App',
            "App #{$appId} was not approved",
            "“{$appName}” did not pass review. You can edit it and ask us to take another look.",
            'Contact support',
            'mailto:'.$support,
        ],
        \App\Enums\AppStatus::Paused => [
            'App',
            "App #{$appId} is paused",
            "“{$appName}” is paused. The wall stops serving offers until it is active again.",
            'Open app settings',
            $appsUrl,
        ],
        default => [
            'App',
            'App status update',
            "Sign in to review app #{$appId}.",
            'Open apps',
            \App\Support\Identity::url().'/dashboard/apps',
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
