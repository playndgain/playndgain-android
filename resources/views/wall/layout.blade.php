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

{{-- Painted while the rest of the body is still on the wire. It carries the
     brand mark and a skeleton of the real card geometry, so the first thing a
     user on a slow connection sees is the shape of the answer rather than an
     empty frame.

     Rendered `hidden` and revealed by the line below it: with JS off there is
     nothing to remove it again, and a splash nobody can dismiss is worse than
     no splash. The script after the content clears it — the body is fully
     parsed by then, and the wall is server-rendered, so parsed is ready. The
     CSS keyframe in wall.css is the backstop for a transfer that never
     finishes at all. --}}
<div class="wall-loader" aria-hidden="true" hidden>
    {{-- Same shell as the content, so the skeleton's columns land exactly
         where the real ones will. --}}
    <div class="wall-shell">
        <div class="wall-loader__brand">
            <x-brand-mark :size="30" />
        </div>

        @unless (isset($partner))
            <div class="wall-skeleton__search"></div>

            <div class="wall-skeleton__chips">
                <span style="inline-size:48px"></span>
                <span style="inline-size:76px"></span>
                <span style="inline-size:64px"></span>
                <span style="inline-size:88px"></span>
            </div>

            <ul class="wall-skeleton">
                @for ($i = 0; $i < 6; $i++)
                    <li class="wall-skeleton__card">
                        <span class="wall-skeleton__tile"></span>
                        <span class="wall-skeleton__body">
                            <span class="wall-skeleton__line"></span>
                            <span class="wall-skeleton__line wall-skeleton__line--short"></span>
                        </span>
                        <span class="wall-skeleton__reward"></span>
                    </li>
                @endfor
            </ul>
        @endunless
    </div>
</div>
<script>document.currentScript.previousElementSibling.hidden = false</script>

@yield('content')
<script>document.querySelector('.wall-loader').remove()</script>

<footer class="wall-footer">
    <span>{{ __('wall.powered_by') }}</span>
    <x-brand-mark :size="16" />
</footer>
</div>
</body>
</html>
