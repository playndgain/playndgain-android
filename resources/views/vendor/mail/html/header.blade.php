@props(['url'])
@php
    // SVG support is still unreliable in inboxes, so mail uses the lossless
    // PNG export of the approved mark. Its URL is stable and carries no
    // recipient identifier; the adjacent wordmark remains when images are off.
    $logoUrl = rtrim(\App\Support\Identity::url(), '/').'/img/brand/playndgain-symbol-2048.png';
@endphp
<tr>
<td class="header-cell" bgcolor="#11131F">
<table class="header-grid" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="mark-image-cell" width="48" valign="middle">
<a href="{{ $url }}" class="mark-image-link" aria-label="{{ \App\Support\Identity::name() }}">
<img class="mark-image" src="{{ $logoUrl }}" width="48" height="48" alt="" />
</a>
</td>
<td class="mark-word-cell">
<a href="{{ $url }}" class="mark-word">{{ \App\Support\Identity::name() }}</a>
<p class="mark-tagline">Play. Complete. Gain.</p>
</td>
<td class="header-kind" align="right" valign="middle">
<span>Platform message</span>
</td>
</tr>
</table>
</td>
</tr>
