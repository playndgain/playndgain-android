@props(['url'])
{{-- The mark, built from a table cell rather than an image or SVG: mail
     clients strip inline SVG, and a hosted logo is a tracking pixel by another
     name. Same geometry as components/brand-mark.blade.php — an amber tile
     carrying a bold "P" beside the wordmark. An anchor must not wrap a table
     (premailer and Outlook both close it early), so the wordmark is the link
     and the tile sits beside it. --}}
<tr>
<td class="header-cell" bgcolor="#11131F">
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="mark-tile" width="28" height="28" align="center" valign="middle" bgcolor="#FFB020">
<span class="mark-glyph" aria-hidden="true">P</span>
</td>
<td class="mark-word-cell">
<a href="{{ $url }}" class="mark-word">{{ \App\Support\Identity::name() }}</a>
</td>
</tr>
</table>
</td>
</tr>
