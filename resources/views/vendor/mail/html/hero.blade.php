@props([
    /** Amber kicker naming the kind of mail: Payout, Account, Ops, or the date. */
    'eyebrow' => null,
    /** The headline. Sentence case; the body below should not repeat it. */
    'title',
    /** 'danger' paints the eyebrow rose — a failure, a refusal. Default is amber. */
    'tone' => 'default',
    /** A money figure. Rendered as the metric in gain green on ink — the one
        place in a mail where money can keep its colour at a passing contrast. */
    'amount' => null,
    /** Small line under the amount: what the figure is. */
    'amountLabel' => null,
])
{{-- The ink band at the top of the card, between the mark and the paper body. --}}
<tr>
<td class="hero-cell{{ $tone === 'danger' ? ' hero-cell-danger' : '' }}" bgcolor="#11131F">
@if ($eyebrow)
<p class="eyebrow{{ $tone === 'danger' ? ' eyebrow-danger' : '' }}">{{ $eyebrow }}</p>
@endif
<p class="hero-title">{{ $title }}</p>
@if ($amount !== null)
<p class="hero-amount">{{ $amount }}</p>
@if ($amountLabel)
<p class="hero-amount-label">{{ $amountLabel }}</p>
@endif
@endif
</td>
</tr>
