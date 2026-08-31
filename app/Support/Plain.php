<?php

namespace App\Support;

/**
 * Network copy, turned back into text.
 *
 * Half the catalogue writes `instructions` as a fragment of HTML — `<br>`
 * between steps, `<b>` around the important word, `&emsp;` for indentation —
 * because it was written for a panel that renders it as markup. The wall
 * escapes everything it prints, so that copy reached the user as the literal
 * string `<b>Open the app</b><br>`, which reads as a broken product.
 *
 * Rendering it as HTML instead is not an option: it is third-party input on a
 * page inside a publisher's iframe. So it is flattened here, once, before it
 * is stored in the wall's cached payload or handed to the detail view — the
 * two places that show it to a human.
 */
final class Plain
{
    /**
     * The copy as the lines its author meant, with the markup gone.
     *
     * `<br>` and the end of a block are line breaks; everything else is
     * dropped. Entities are decoded first so that copy which arrived
     * double-escaped (`&lt;br&gt;`) is flattened too rather than printed.
     *
     * @return list<string>
     */
    public static function lines(?string $html): array
    {
        if (blank($html)) {
            return [];
        }

        $text = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('#<\s*(br|/p|/div|/li|/tr)[^>]*>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);

        return array_values(array_filter(array_map(
            // `trim()` is a byte mask. Passing `\u{00A0}` (C2 A0) made it
            // strip the C2 off `¡` (C2 A1) and leave a lone 0xA1 — MySQL
            // then rejected the row as `Incorrect string value: '\xA1Sea...'`.
            // `mb_trim()` is character-wise and still drops nbsp / em-space.
            fn (string $line): string => mb_trim($line),
            preg_split('/\r\n|\r|\n/', $text) ?: [],
        )));
    }

    /** The same copy on one line, for a card that has room for one. */
    public static function text(?string $html): string
    {
        return implode(' ', self::lines($html));
    }
}
