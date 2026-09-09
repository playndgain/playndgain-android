@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
    // Amber is the one action colour. Rose is the only other fill and it
    // means a failure. 'success' maps onto amber on purpose: gain green is
    // money, never decoration.
    [$fill, $ink] = match ($color) {
        'error', 'red' => ['#E5484D', '#0C0D1A'],
        default => ['#FFB020', '#1A1000'],
    };
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td bgcolor="{{ $fill }}" style="border-radius:999px;background-color:{{ $fill }};">
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="color:{{ $ink }};">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
