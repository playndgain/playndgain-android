<?php

namespace App\Support;

/**
 * WCAG 2.1 relative-luminance contrast, for the wall theme picker — docs/08 §4.
 *
 * Publishers pick unreadable colour pairs constantly. Telling them at the
 * moment they pick is cheaper than the support thread that follows a wall
 * nobody can read.
 *
 * It lives here rather than under the Filament tree because the wall itself
 * uses it too — `WallContext` picks the reward pill's foreground with it, and
 * the wall must never boot a panel namespace.
 */
final class Contrast
{
    /** Normal body text has to clear this. */
    public const AA_NORMAL = 4.5;

    public static function ratio(string $foreground, string $background): ?float
    {
        $first = self::luminance($foreground);
        $second = self::luminance($background);

        if ($first === null || $second === null) {
            return null;
        }

        [$light, $dark] = $first > $second ? [$first, $second] : [$second, $first];

        return ($light + 0.05) / ($dark + 0.05);
    }

    public static function verdict(string $foreground, string $background): string
    {
        $ratio = self::ratio($foreground, $background);

        if ($ratio === null) {
            return '<span class="text-gray-400">Pick a text and background colour to check readability.</span>';
        }

        $formatted = number_format($ratio, 2);

        return $ratio >= self::AA_NORMAL
            ? "<span class=\"text-success-400\">Contrast {$formatted}:1 — passes WCAG AA.</span>"
            : "<span class=\"text-danger-400\">Contrast {$formatted}:1 — below the 4.5:1 minimum. "
                .'Users on phones in daylight will not be able to read your wall.</span>';
    }

    /**
     * The readable foreground for a background nobody validated: black or
     * white, whichever WCAG puts further from it.
     *
     * The wall needs this because the reward pill fills with the publisher's
     * own primary, and a primary can be any brightness — a hardcoded near-black
     * label is invisible on a dark accent, which is the colour-alone failure
     * the wall polices everywhere else. Worst case is a mid-grey primary at
     * ~4.5:1, and the figure sitting on it is 19px bold — past the 18.66px
     * where WCAG starts counting text as large and asking only 3:1.
     */
    public static function on(string $background): string
    {
        $light = self::ratio('#FFFFFF', $background);
        $dark = self::ratio('#0B0D10', $background);

        if ($light === null || $dark === null) {
            return '#FFFFFF';
        }

        return $light >= $dark ? '#FFFFFF' : '#0B0D10';
    }

    /** @return ?array{int, int, int} */
    private static function rgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');

        // `#RGBA` and `#RRGGBBAA` are both legal theme input. Alpha does not
        // change the hue being measured, so it is dropped rather than refused —
        // refusing it leaves the caller with no colour at all.
        if (strlen($hex) === 4 || strlen($hex) === 8) {
            $hex = substr($hex, 0, strlen($hex) === 4 ? 3 : 6);
        }

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function luminance(string $hex): ?float
    {
        $rgb = self::rgb($hex);

        if ($rgb === null) {
            return null;
        }

        $channels = array_map(function (int $value): float {
            $srgb = $value / 255;

            return $srgb <= 0.03928
                ? $srgb / 12.92
                : (($srgb + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
