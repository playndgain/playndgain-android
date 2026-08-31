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

    <form method="post" action="{{ route('wall.consent') }}" class="wall-actions">
        {{-- No CSRF token: the wall group is stateless by specification and an
             iframe on another origin never receives our session cookie. --}}
        <input type="hidden" name="appid" value="{{ $context->app->app_id }}">
        <input type="hidden" name="userid" value="{{ $context->endUserId }}">
        <input type="hidden" name="sig" value="{{ request()->input('sig') }}">
        <input type="hidden" name="lang" value="{{ $context->locale }}">

        <button type="submit" name="action" value="accept" class="wall-button">{{ __('wall.consent.accept') }}</button>
        <button type="submit" name="action" value="decline" class="wall-button">{{ __('wall.consent.decline') }}</button>
    </form>

    <p class="wall-panel__note">{{ __('wall.consent.note') }}</p>
</main>
@endsection
