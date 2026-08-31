{{-- Postback is on or it is not. The URL is the tooltip — printing it in
     the cell would push every other column off the scan line. --}}
@php
    $url = is_string($getState()) && filled($getState()) ? $getState() : null;
@endphp

<div class="ad-status-cell">
    @if ($url === null)
        <span class="ad-postback ad-postback--off">
            <span class="ad-postback__dot" aria-hidden="true"></span>
            None
        </span>
    @else
        <span class="ad-postback ad-postback--on"
              x-tooltip="{ content: @js($url), theme: $store.theme }"
              aria-label="{{ $url }}">
            <span class="ad-postback__dot" aria-hidden="true"></span>
            Live
        </span>
    @endif
</div>
