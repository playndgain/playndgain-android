@props([
    /** Label => value. Values are printed as text; nothing here is markdown. */
    'rows' => [],
])
{{-- The fact ledger: the wall's hairline rows, in a mail. Label left, value
     right. No money here — a figure that is money goes in the hero band,
     where it can keep its colour at a contrast that passes on ink. --}}
<table class="facts" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $label => $value)
<tr>
<td class="facts-label">{{ $label }}</td>
<td class="facts-value" align="right">{{ $value }}</td>
</tr>
@endforeach
</table>
