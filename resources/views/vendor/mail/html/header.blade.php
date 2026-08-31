@props(['url'])
{{-- A link must not wrap a table — premailer and Outlook both close the
     anchor before the first `<table>`, which left the mark unclickable.
     The wordmark is the link. The glyph sits beside it. --}}
<tr>
<td class="header" align="center" bgcolor="#08090A">
<table cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td style="vertical-align:middle;padding-right:10px;">
<svg class="logo" width="26" height="26" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<rect x="1" y="1" width="24" height="24" rx="7" stroke="#262B33" stroke-width="1.5"/>
<path d="M8 7.5L13 18.5L18 7.5" stroke="#F5A524" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
</td>
<td style="vertical-align:middle;">
<a href="{{ $url }}" style="font-family:Arial,Helvetica,sans-serif;font-size:17px;font-weight:bold;letter-spacing:-0.3px;color:#E9ECF1;text-decoration:none;">
{{ \App\Support\Identity::name() }}
</a>
</td>
</tr>
</table>
</td>
</tr>
