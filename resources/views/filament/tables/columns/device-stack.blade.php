{{-- Device targeting as stacked brand marks. An empty list is every device. --}}
@php
    // Only devices with a mark to show. Anything else the vocabulary grows into
    // stays a label rather than rendering a broken image.
    $marks = ['android', 'ios', 'desktop'];

    $devices = collect($getState() ?? [])
        ->filter(fn (mixed $device): bool => is_string($device) && $device !== '')
        ->unique()
        ->values();
@endphp

@if ($devices->isEmpty())
    <p class="fi-ta-placeholder">all</p>
@else
    <div class="ad-stack"
         x-tooltip="{ content: @js($devices->implode(', ')), theme: $store.theme }"
         aria-label="{{ $devices->implode(', ') }}">
        @foreach ($devices as $device)
            @if (in_array($device, $marks, true))
                <span class="ad-device">
                    <img src="{{ asset('img/devices/'.$device.'.svg') }}"
                         alt="{{ $device }}"
                         loading="lazy">
                </span>
            @else
                <span class="ad-more">{{ $device }}</span>
            @endif
        @endforeach
    </div>
@endif
