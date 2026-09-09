@props(['eyebrow' => null, 'title', 'tone' => 'default', 'amount' => null, 'amountLabel' => null])
@if ($eyebrow){{ strtoupper($eyebrow) }} — @endif{{ $title }}
@if ($amount !== null)

{{ $amount }}@if ($amountLabel) ({{ $amountLabel }})@endif
@endif

