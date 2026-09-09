@php
    /** @var \App\Services\Wall\WallContext|null $context */
    $ctx = $context ?? null;
    $vars = $ctx?->cssVars() ?? \App\Services\Wall\WallContext::defaultVars();
@endphp
<!doctype html>
<html lang="{{ $ctx?->locale ?? 'en' }}" dir="{{ $ctx?->dir() ?? 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- The wall lives inside someone else's page and has nothing to index. --}}
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="{{ ($ctx?->isDark() ?? true) ? 'dark' : 'light' }}">
<title>@yield('title', __('wall.title'))</title>

{{-- Per-app theming, docs/03 §5: custom properties on :root, no per-app CSS
     file and no per-app build. Values are whitelisted in WallContext before
     they reach this block — publisher input, inside a <style>. --}}
<style>:root{@foreach ($vars as $property => $value){{ $property }}:{{ $value }};@endforeach}</style>

@vite('resources/js/wall.js')
</head>
<body class="wall @if (! ($ctx?->isDark() ?? true)) wall--light @endif">

{{-- One copy of every glyph the cards reference with <use>. A hundred cards
     carrying device icons is a hundred forty-byte references rather than a
     hundred copies of the path. --}}
@include('partials.icon-sprite')

{{-- Frosted column over the atmosphere. The image lives on `.wall::before`;
     this pane is the glass the catalogue sits on, so the wallpaper can show
     around the frame and through the frost without painting the cards. --}}
<div class="wall-pane">

{{-- Covers the frame from first paint until the face and Alpine are ready.
     wall.js fades it out. The CSS 6s keyframe is the backstop for a transfer
     that never finishes. With JS off a <noscript> rule hides it, because a
     splash nobody can dismiss is worse than no splash. --}}
<div class="wall-loader" aria-hidden="true">
    <div class="wall-loader__stage">
        @if ($ctx !== null)
            <span class="wall-loader__mark" aria-hidden="true">{{ mb_substr($ctx->app->name, 0, 1) }}</span>
            <span class="wall-loader__name">{{ $ctx->app->name }}</span>
            <span class="wall-loader__title">{{ __('wall.earn', ['currency' => $ctx->app->currency_name]) }}</span>
        @else
            <span class="wall-loader__title">{{ __('wall.title') }}</span>
        @endif
        <span class="wall-loader__spin"></span>
    </div>
</div>
<noscript><style>.wall-loader{visibility:hidden!important;pointer-events:none}</style></noscript>

@yield('content')

<footer class="wall-footer">
    <span>{{ __('wall.powered_by') }}</span>
    <x-brand-mark :size="14" />
</footer>
</div>
</body>
</html>
