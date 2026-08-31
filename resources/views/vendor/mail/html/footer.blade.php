@php
    $support = \App\Support\Identity::supportEmail();
@endphp
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center" style="padding:28px 16px 0;">
{{ Illuminate\Mail\Markdown::parse($slot) }}
<p style="margin:8px 0 0;color:#6E7784;font-size:12px;">
<a href="mailto:{{ $support }}" style="color:#6E7784;text-decoration:underline;">{{ $support }}</a>
</p>
</td>
</tr>
</table>
</td>
</tr>
