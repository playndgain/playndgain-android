<?php

namespace App\Filament\Support;

/**
 * The one place a letter-in-a-circle is drawn, for the two things in the panels
 * that have no picture of their own: an account with no avatar, and a row whose
 * icon is missing or dead.
 *
 * It is a data URI rather than a file or a Blade view because both callers hand
 * their result to something that wants a URL — Filament's `AvatarProvider`
 * contract and `ImageColumn::defaultImageUrl()` — and because a chip that costs
 * no request cannot itself 404 and become the broken image it replaces.
 *
 * `on-amber` on `amber-400` is 12:1 (design.md §8), the pair the site already
 * uses for text on an amber fill. One ground for every chip on purpose:
 * hashing a name to a colour is the reflex, and AGENTS.md §2 bans palettes that
 * are not in the token files. The letter carries the distinction.
 *
 * The font stack is written out rather than referenced as a token because a
 * data URI cannot read CSS custom properties. It is the same stack theme.css
 * already sets on the panels.
 */
class InitialsChip
{
    private const GROUND = '#FFB020';   // amber-400

    private const INK = '#1A1000';      // on-amber

    /**
     * The first letter of `$name`, upper-cased, on an amber disc.
     *
     * An empty or symbol-only name leaves a bare chip, which reads better than
     * a broken glyph — and better than the alternative of falling back to some
     * other field, which on a publisher-facing offer row could only be the
     * network (AGENTS.md, anonymity).
     */
    public static function for(?string $name): string
    {
        $ground = self::GROUND;
        $ink = self::INK;
        $letter = htmlspecialchars(
            mb_strtoupper(mb_substr(trim((string) $name), 0, 1)),
            ENT_QUOTES | ENT_XML1
        );

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
                <circle cx="32" cy="32" r="32" fill="{$ground}"/>
                <text x="32" y="33" fill="{$ink}" font-family="Sora, system-ui, sans-serif" font-size="28" font-weight="600" text-anchor="middle" dominant-baseline="central">{$letter}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
