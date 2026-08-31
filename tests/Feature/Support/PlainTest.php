<?php

use App\Support\Plain;

it('keeps a leading inverted exclamation as utf-8', function () {
    // Skylup ships `<p>¡Sea recompensado…`. PHP `trim()` is a byte mask, and
    // the nbsp we used to pass it (`\u{00A0}` = C2 A0) made it strip the C2
    // off `¡` (C2 A1). The leftover 0xA1 is what MySQL rejected as
    // `Incorrect string value: '\xA1Sea...'`.
    $text = Plain::text('<p>¡Sea recompensado por cada encuesta</p>');

    expect($text)->toStartWith('¡')
        ->and($text)->toContain('Sea recompensado')
        ->and(mb_check_encoding($text, 'UTF-8'))->toBeTrue()
        ->and(bin2hex(substr($text, 0, 2)))->toBe('c2a1');
});

it('still drops leading and trailing nbsp and em-space', function () {
    expect(Plain::text("\u{00A0}<p>Hello</p>\u{00A0}"))->toBe('Hello')
        ->and(Plain::text("\u{2003}<p>Hello</p>\u{2002}"))->toBe('Hello');
});
