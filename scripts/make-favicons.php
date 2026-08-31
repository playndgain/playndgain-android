<?php

/**
 * Renders the brand mark into the raster favicon set.
 *
 * Kept as a script rather than run once and deleted: the mark is one component
 * now, and the next time it is nudged these files have to be nudged with it.
 * `php scripts/make-favicons.php` regenerates all of them.
 *
 * GD has no SVG support, so the mark is drawn with primitives at 1024px and
 * downscaled — imagecopyresampled is the anti-aliasing here, because GD's
 * imageantialias() does nothing for thick strokes or filled polygons.
 */
const OBSIDIAN = [0x0E, 0x10, 0x13];
const AMBER = [0xF5, 0xA5, 0x24];

/** Drawn at this size, then downscaled. Large enough that the round caps survive. */
const CANVAS = 1024;

$public = dirname(__DIR__).'/public';

// ---------------------------------------------------------------------------
// The mark
// ---------------------------------------------------------------------------

/**
 * The glyph on a filled square.
 *
 * `$bleed` fills the corners instead of rounding them: iOS masks its own
 * corners onto apple-touch-icon and backfills transparency with black, so a
 * rounded PNG there shows black shoulders inside the mask.
 */
function drawMark(bool $bleed): GdImage
{
    $image = imagecreatetruecolor(CANVAS, CANVAS);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $transparent);

    $obsidian = imagecolorallocate($image, ...OBSIDIAN);
    $amber = imagecolorallocate($image, ...AMBER);

    if ($bleed) {
        imagefilledrectangle($image, 0, 0, CANVAS - 1, CANVAS - 1, $obsidian);
    } else {
        // 26-unit viewBox scaled up: a 24-unit square at (1,1) with a 7-unit
        // corner radius, the same geometry the SVG component draws.
        $scale = CANVAS / 26;
        roundedRect($image, $scale, $scale, 25 * $scale, 25 * $scale, 7 * $scale, $obsidian);
    }

    // The V, in the same 26-unit space: M8 7.5 L13 18.5 L18 7.5.
    $scale = CANVAS / 26;
    $stroke = 2.9 * $scale;

    $left = [8 * $scale, 7.5 * $scale];
    $bottom = [13 * $scale, 18.5 * $scale];
    $right = [18 * $scale, 7.5 * $scale];

    thickLine($image, $left, $bottom, $stroke, $amber);
    thickLine($image, $bottom, $right, $stroke, $amber);

    // Round caps and the join, which a pair of quads leaves square.
    foreach ([$left, $bottom, $right] as [$x, $y]) {
        imagefilledellipse($image, (int) round($x), (int) round($y), (int) round($stroke), (int) round($stroke), $amber);
    }

    return $image;
}

/** A stroked segment as a filled quad, offset perpendicular by half the width. */
function thickLine(GdImage $image, array $from, array $to, float $width, int $colour): void
{
    [$x1, $y1] = $from;
    [$x2, $y2] = $to;

    $length = hypot($x2 - $x1, $y2 - $y1);

    if ($length === 0.0) {
        return;
    }

    $nx = -($y2 - $y1) / $length * $width / 2;
    $ny = ($x2 - $x1) / $length * $width / 2;

    imagefilledpolygon($image, [
        (int) round($x1 + $nx), (int) round($y1 + $ny),
        (int) round($x2 + $nx), (int) round($y2 + $ny),
        (int) round($x2 - $nx), (int) round($y2 - $ny),
        (int) round($x1 - $nx), (int) round($y1 - $ny),
    ], $colour);
}

/** A cross of two rectangles plus four corner ellipses. */
function roundedRect(GdImage $image, float $x1, float $y1, float $x2, float $y2, float $radius, int $colour): void
{
    $r = (int) round($radius);
    [$x1, $y1, $x2, $y2] = array_map(fn (float $v): int => (int) round($v), [$x1, $y1, $x2, $y2]);

    imagefilledrectangle($image, $x1 + $r, $y1, $x2 - $r, $y2, $colour);
    imagefilledrectangle($image, $x1, $y1 + $r, $x2, $y2 - $r, $colour);

    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
        imagefilledellipse($image, $cx, $cy, $r * 2, $r * 2, $colour);
    }
}

function resize(GdImage $source, int $size): GdImage
{
    $target = imagecreatetruecolor($size, $size);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefilledrectangle($target, 0, 0, $size, $size, imagecolorallocatealpha($target, 0, 0, 0, 127));
    imagealphablending($target, true);

    imagecopyresampled($target, $source, 0, 0, 0, 0, $size, $size, CANVAS, CANVAS);

    return $target;
}

function png(GdImage $image): string
{
    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// ICO container
// ---------------------------------------------------------------------------

/**
 * PNG-in-ICO. Every browser since IE11 reads it, and it avoids hand-rolling
 * a BMP with an AND mask for two images nobody will ever see at full size.
 *
 * @param  array<int, string>  $images  size => PNG bytes
 */
function ico(array $images): string
{
    $count = count($images);
    $header = pack('vvv', 0, 1, $count);
    $offset = 6 + (16 * $count);

    $directory = '';
    $body = '';

    foreach ($images as $size => $png) {
        $directory .= pack(
            'CCCCvvVV',
            $size >= 256 ? 0 : $size,   // 0 means 256
            $size >= 256 ? 0 : $size,
            0,                          // palette
            0,                          // reserved
            1,                          // colour planes
            32,                         // bits per pixel
            strlen($png),
            $offset,
        );

        $body .= $png;
        $offset += strlen($png);
    }

    return $header.$directory.$body;
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

$rounded = drawMark(bleed: false);
$bleed = drawMark(bleed: true);

$written = [];

// The one browsers prefer when they support it — vector, so it never softens.
$svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 26 26" width="26" height="26">
  <rect width="26" height="26" rx="7" fill="#0E1013"/>
  <path d="M8 7.5L13 18.5L18 7.5" fill="none" stroke="#F5A524" stroke-width="2.8"
        stroke-linecap="round" stroke-linejoin="round"/>
</svg>

SVG;

file_put_contents("{$public}/favicon.svg", $svg);
$written['favicon.svg'] = strlen($svg);

$icoBytes = ico([
    16 => png(resize($rounded, 16)),
    32 => png(resize($rounded, 32)),
    48 => png(resize($rounded, 48)),
]);

file_put_contents("{$public}/favicon.ico", $icoBytes);
$written['favicon.ico'] = strlen($icoBytes);

$apple = png(resize($bleed, 180));
file_put_contents("{$public}/apple-touch-icon.png", $apple);
$written['apple-touch-icon.png'] = strlen($apple);

foreach ($written as $name => $bytes) {
    echo str_pad($name, 24).number_format($bytes)." bytes\n";
}
