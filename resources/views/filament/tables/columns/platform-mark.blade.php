{{-- Platform as the same device marks the offers table uses. "All" stacks
     the three; a single platform is one glyph. The name is the tooltip. --}}
@php
    use App\Enums\AppPlatform;

    $platform = $getState();

    if (! $platform instanceof AppPlatform && is_string($platform)) {
        $platform = AppPlatform::tryFrom($platform);
    }

    $marks = $platform instanceof AppPlatform ? $platform->deviceMarks() : [];
    $label = $platform instanceof AppPlatform ? $platform->getLabel() : '';
@endphp

@if ($marks === [])
    <p class="fi-ta-placeholder ad-flag-cell">—</p>
@else
    <div class="ad-stack ad-stack--platform"
         x-tooltip="{ content: @js($label), theme: $store.theme }"
         aria-label="{{ $label }}">
        @foreach ($marks as $mark)
            <span class="ad-device">
                <img src="{{ asset('img/devices/'.$mark.'.svg') }}"
                     alt="{{ $mark }}"
                     loading="lazy">
            </span>
        @endforeach
    </div>
@endif
