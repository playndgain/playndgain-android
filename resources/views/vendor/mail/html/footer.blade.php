@php
    $identity = \App\Support\Identity::class;
    $support = $identity::supportEmail();
    $links = [
        'Dashboard' => $identity::url().'/dashboard',
        'Docs' => route('docs'),
        'Support' => 'mailto:'.$support,
    ];
@endphp
<tr>
<td align="center">
<table class="footer" align="center" width="560" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-cell" align="center">
<p class="footer-links">
@foreach ($links as $label => $href)
<a href="{{ $href }}">{{ $label }}</a>@if (! $loop->last)<span class="footer-dot" aria-hidden="true">·</span>@endif
@endforeach
</p>
{{ Illuminate\Mail\Markdown::parse($slot) }}
<p class="footer-note">This is a transactional message from {{ $identity::name() }}. Questions go to <a href="mailto:{{ $support }}">{{ $support }}</a>.</p>
</td>
</tr>
</table>
</td>
</tr>
