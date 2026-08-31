@extends('wall.layout')

@section('title', $title)

@php
    /** @var \App\Services\Wall\WallContext|null $context */
    $ctx = $context ?? null;
@endphp

@section('content')
{{-- One screen for every dead end. A user who cannot proceed needs a reason,
     not four designs. --}}
<main class="wall-panel wall-panel--center">
    <h1 class="wall-panel__title">{{ $title }}</h1>
    <p class="wall-panel__lede">{{ $body }}</p>

    @if ($ctx !== null)
        <p class="wall-actions">
            <a class="wall-button" href="{{ route('wall') }}?{{ $ctx->params() }}">{{ __('wall.notice.back') }}</a>

            @if (($declined ?? false) === true)
                {{-- Consent has to be withdrawable and re-grantable, or the
                     first answer is permanent and the gate is a trap. --}}
                <a class="wall-button" href="{{ route('wall') }}?{{ $ctx->params() }}&consent=review">{{ __('wall.consent.review') }}</a>
            @endif
        </p>
    @endif
</main>
@endsection
