<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ \App\Support\Identity::name() }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
/* Sora from our own domain — the only face the brand has. Apple Mail and iOS
   Mail fetch it; every other client shows the system stack the site uses as
   its fallback. Nothing here is loaded from a third party. */
@font-face {
    font-family: 'Sora';
    font-style: normal;
    font-weight: 100 800;
    font-display: swap;
    src: url('{{ \App\Support\Identity::url() }}/fonts/sora-latin-wght-normal.woff2') format('woff2');
}
@media only screen and (max-width: 600px) {
    .inner-body, .footer { width: 100% !important; }
    .header-cell, .hero-cell, .content-cell { padding-left: 24px !important; padding-right: 24px !important; }
    .hero-title { font-size: 24px !important; }
    .hero-amount { font-size: 32px !important; }
}
@media only screen and (max-width: 500px) {
    .action, .action table { width: 100% !important; }
    .button { display: block !important; width: 100% !important; box-sizing: border-box; text-align: center !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body style="margin:0;padding:0;background-color:#0A0B14;">
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#0A0B14">
<tr>
<td align="center" bgcolor="#0A0B14" style="padding:40px 16px 32px;">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="inner-body" align="center" width="560" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}
{!! $hero ?? '' !!}
<tr>
<td class="content-cell" bgcolor="#FFFFFF">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>
{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
