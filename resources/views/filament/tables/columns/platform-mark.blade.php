{{-- Platform as a labelled pill, shaped like the postback pill one column
     over. The bare device mark it replaced was a 13px monochrome glyph on
     ink and read as an empty cell. One platform shows its mark; "All
     platforms" shows the words alone, since three 13px glyphs overlapping
     is the same illegibility this pill exists to fix. --}}
@php
    use App\Enums\AppPlatform;

    $platform = $getState();

    if (! $platform instanceof AppPlatform && is_string($platform)) {
        $platform = AppPlatform::tryFrom($platform);
    }

    $marks = $platform instanceof AppPlatform ? $platform->deviceMarks() : [];
    $label = $platform instanceof AppPlatform ? $platform->getLabel() : '';
@endphp

<div class="ad-status-cell">
    @if ($marks === [])
        <p class="fi-ta-placeholder">—</p>
    @else
        <span class="ad-platform" aria-label="{{ $label }}">
            @if (count($marks) === 1)
                <span class="ad-platform__marks" aria-hidden="true">
                    <img src="{{ asset('img/devices/'.$marks[0].'.svg') }}" alt="">
                </span>
            @endif

            {{ $label }}
        </span>
    @endif
</div>
