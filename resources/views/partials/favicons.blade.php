{{-- One icon set, every HTML surface. The query string is the SVG's mtime so
     a regenerated mark actually replaces the tab icon browsers cache forever.
     Rebuild with `php scripts/make-favicons.php`. --}}
@php($v = (int) @filemtime(public_path('favicon.svg')))
<link rel="icon" href="/favicon.ico?v={{ $v }}" sizes="32x32">
<link rel="icon" href="/favicon.svg?v={{ $v }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ $v }}">
