<?php

/**
 * Renders the brand mark into the favicon set and the house-network logo.
 *
 * Kept as a script rather than run once and deleted: the mark is one component
 * (resources/views/components/brand-mark.blade.php), and the next time it is
 * nudged these files have to be nudged with it. `php scripts/make-favicons.php`
 * regenerates all of them.
 *
 * The mark is a rounded amber tile carrying a bold geometric "P" — a stem and
 * a stroked bowl — in a 64-unit box. Geometry, not text: GD has no fonts to
 * get wrong. The numbers below are the component's numbers; change them in
 * both places or in neither.
 *
 * Drawn at 1024px and downscaled: imagecopyresampled is the anti-aliasing,
 * because imageantialias() does nothing for filled shapes.
 */
const AMBER = [0xFF, 0xB0, 0x20];
const AMBER_DEEP = [0xF2, 0x8C, 0x1A];
const ON_AMBER = [0x1A, 0x10, 0x00];

/** Drawn at this size, then downscaled. */
const CANVAS = 1024;

/** The component's viewBox. */
const BOX = 64;
const TILE_RADIUS = 16;

/** The stem: x, y, width, height. */
const STEM = [15.5, 13.5, 11, 38];

/** The bowl: a stroked half-ring. Centre x/y, arc radius, stroke width, bar start x. */
const BOWL_CX = 33;
const BOWL_CY = 30;
const BOWL_R = 11;
const BOWL_STROKE = 11;
const BOWL_FROM_X = 21;

$public = dirname(__DIR__).'/public';

// ---------------------------------------------------------------------------
// The mark
// ---------------------------------------------------------------------------

/**
 * The glyph on an amber tile.
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

    $amber = imagecolorallocate($image, ...AMBER);
    $ink = imagecolorallocate($image, ...ON_AMBER);

    $u = CANVAS / BOX;
    $px = fn (float $v): int => (int) round($v * $u);

    if ($bleed) {
        imagefilledrectangle($image, 0, 0, CANVAS - 1, CANVAS - 1, $amber);
    } else {
        roundedRect($image, 0, 0, CANVAS, CANVAS, TILE_RADIUS * $u, $amber);
    }

    // The bowl: a full ring, then its left half painted back to amber. The
    // stem and the two bars are drawn afterwards, so the erase cannot touch them.
    $outer = BOWL_R + BOWL_STROKE / 2;
    $inner = BOWL_R - BOWL_STROKE / 2;
    imagefilledellipse($image, $px(BOWL_CX), $px(BOWL_CY), $px($outer * 2), $px($outer * 2), $ink);
    imagefilledellipse($image, $px(BOWL_CX), $px(BOWL_CY), $px($inner * 2), $px($inner * 2), $amber);
    imagefilledrectangle($image, $px(BOWL_CX - $outer - 1), $px(BOWL_CY - $outer - 1), $px(BOWL_CX) - 1, $px(BOWL_CY + $outer + 1), $amber);

    // The bars from the stem to the bowl, top and bottom.
    foreach ([BOWL_CY - BOWL_R, BOWL_CY + BOWL_R] as $y) {
        imagefilledrectangle($image, $px(BOWL_FROM_X), $px($y - BOWL_STROKE / 2), $px(BOWL_CX), $px($y + BOWL_STROKE / 2) - 1, $ink);
    }

    [$sx, $sy, $sw, $sh] = STEM;
    roundedRect($image, $sx * $u, $sy * $u, ($sx + $sw) * $u, ($sy + $sh) * $u, 1.5 * $u, $ink);

    return $image;
}

/** A cross of two rectangles plus four corner ellipses. */
function roundedRect(GdImage $image, float $x1, float $y1, float $x2, float $y2, float $radius, int $colour): void
{
    $r = (int) round($radius);
    [$x1, $y1, $x2, $y2] = array_map(fn (float $v): int => (int) round($v), [$x1, $y1, $x2, $y2]);

    imagefilledrectangle($image, $x1 + $r, $y1, $x2 - $r - 1, $y2 - 1, $colour);
    imagefilledrectangle($image, $x1, $y1 + $r, $x2 - 1, $y2 - $r - 1, $colour);

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

$hex = fn (array $rgb): string => strtoupper(implode('', array_map(fn (int $c): string => str_pad(dechex($c), 2, '0', STR_PAD_LEFT), $rgb)));

$rounded = drawMark(bleed: false);
$bleed = drawMark(bleed: true);

$written = [];

// The one browsers prefer when they support it — vector, so it never softens.
// Same primitives as the component, gradient included.
[$sx, $sy, $sw, $sh] = STEM;
$svg = sprintf(
    "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 %1\$d %1\$d\" width=\"%1\$d\" height=\"%1\$d\">\n".
    "  <defs><linearGradient id=\"g\" x1=\"0\" y1=\"0\" x2=\"%1\$d\" y2=\"%1\$d\" gradientUnits=\"userSpaceOnUse\"><stop offset=\"0\" stop-color=\"#FFC554\"/><stop offset=\"1\" stop-color=\"#%3\$s\"/></linearGradient></defs>\n".
    "  <rect width=\"%1\$d\" height=\"%1\$d\" rx=\"%2\$d\" fill=\"url(#g)\"/>\n".
    "  <rect x=\"%5\$s\" y=\"%6\$s\" width=\"%7\$s\" height=\"%8\$s\" rx=\"1.5\" fill=\"#%4\$s\"/>\n".
    "  <path d=\"M%9\$s %10\$sH%11\$sa%12\$s %12\$s 0 0 1 0 %13\$sH%9\$s\" fill=\"none\" stroke=\"#%4\$s\" stroke-width=\"%14\$s\"/>\n".
    "</svg>\n",
    BOX,
    TILE_RADIUS,
    $hex(AMBER_DEEP),
    $hex(ON_AMBER),
    $sx, $sy, $sw, $sh,
    BOWL_FROM_X,
    BOWL_CY - BOWL_R,
    BOWL_CX,
    BOWL_R,
    BOWL_R * 2,
    BOWL_STROKE,
);

file_put_contents("{$public}/favicon.svg", $svg);
$written['favicon.svg'] = strlen($svg);

// Same mark as the house channel's seeded logo. One write, two paths, so
// the admin list cannot keep a leftover chevron after the tile is nudged.
file_put_contents("{$public}/img/networks/house.svg", $svg);
$written['img/networks/house.svg'] = strlen($svg);

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
