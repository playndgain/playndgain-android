@extends('wall.layout')

@section('title', __('wall.consent.title'))

@php
    /** @var \App\Services\Wall\WallContext $context */
@endphp

@section('content')
{{-- Plain, not a dark pattern: what is collected, why, who receives it, and
     two buttons with the same weight. Decline is a real answer — docs/03 §4. --}}
<main class="wall-panel">
    <h1 class="wall-panel__title">{{ __('wall.consent.title') }}</h1>
    <p class="wall-panel__lede">{{ __('wall.consent.intro') }}</p>

    <dl class="wall-terms">
        <dt>{{ __('wall.consent.what_label') }}</dt>
        <dd>{{ __('wall.consent.what') }}</dd>
        <dt>{{ __('wall.consent.why_label') }}</dt>
        <dd>{{ __('wall.consent.why') }}</dd>
        <dt>{{ __('wall.consent.who_label') }}</dt>
        <dd>{{ __('wall.consent.who') }}</dd>
    </dl>

    {{-- `appid` rides in the query string too: the per-app rate limiter keys on
         the query, and a body-only appid lands every consent POST in one
         shared bucket. Every other wall parameter is carried as a hidden
         field so the redirect back to the wall keeps sub-ids, SDK info,
         theme and balance — not just who the user is. --}}
    <form method="post" action="{{ route('wall.consent') }}?appid={{ $context->app->app_id }}" class="wall-actions">
        {{-- No CSRF token: the wall group is stateless by specification and an
             iframe on another origin never receives our session cookie. --}}
        @php(parse_str($context->params(), $carried))
        {{-- Not `country`: the consent row records where the user really is,
             and a test-mode preview override must not be written as that. --}}
        @foreach (\Illuminate\Support\Arr::except($carried, ['country']) as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <button type="submit" name="action" value="accept" class="wall-button">{{ __('wall.consent.accept') }}</button>
        <button type="submit" name="action" value="decline" class="wall-button">{{ __('wall.consent.decline') }}</button>
    </form>

    <p class="wall-panel__note">{{ __('wall.consent.note') }}</p>
</main>
@endsection
