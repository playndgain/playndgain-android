@php
    $appId = (string) $app->app_id;
    $appName = (string) $app->name;
    $appsUrl = \App\Support\Identity::url().'/dashboard/apps/'.$app->getRouteKey().'/edit';
    $support = \App\Support\Identity::supportEmail();

    [$title, $body] = match ($status) {
        \App\Enums\AppStatus::Active => [
            "App #{$appId} is live",
            "“{$appName}” is approved. The wall can serve live offers to your users.",
        ],
        \App\Enums\AppStatus::Rejected => [
            "App #{$appId} was not approved",
            "“{$appName}” did not pass review. Contact {$support} if you need help.",
        ],
        \App\Enums\AppStatus::Paused => [
            "App #{$appId} is paused",
            "“{$appName}” is paused. The wall stops serving offers until it is active again.",
        ],
        default => [
            'App status update',
            "Sign in to review app #{$appId}.",
        ],
    };
@endphp
{!! $title !!}

{!! $body !!}

@if (filled($reason))
{!! $reason !!}
@endif

{!! $appsUrl !!}
