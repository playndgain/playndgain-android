<?php

namespace App\Identity;

/**
 * The wall `ds` token — compact, client-controlled, incriminating-only.
 *
 * A farm can forge a clean token. That is worth nothing: a missing or junk
 * value is ignored, never treated as suspicion. After a hard length cap and a
 * strict shape check we keep a hash, never the payload.
 */
final class DeviceSignals
{
    public const MAX_LENGTH = 256;

    public static function hash(?string $ds): ?string
    {
        $payload = self::decode($ds);

        return $payload === null
            ? null
            : hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return ?array{c: int, m: float, p: float, r: string, t: int, w: int}
     */
    public static function decode(?string $ds): ?array
    {
        if (! is_string($ds) || $ds === '' || strlen($ds) > self::MAX_LENGTH) {
            return null;
        }

        $padded = strtr($ds, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json = base64_decode($padded, true);

        if (! is_string($json) || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        $renderer = $data['r'] ?? null;
        $cores = $data['c'] ?? null;
        $memory = $data['m'] ?? null;
        $touch = $data['t'] ?? null;
        $ratio = $data['p'] ?? null;
        $webdriver = $data['w'] ?? null;

        if (! is_string($renderer) || strlen($renderer) > 80) {
            return null;
        }

        if (! is_int($cores) && ! (is_float($cores) && $cores == (int) $cores)) {
            return null;
        }

        if (! is_int($touch) && ! (is_float($touch) && $touch == (int) $touch)) {
            return null;
        }

        if (! is_numeric($memory) || ! is_numeric($ratio)) {
            return null;
        }

        if ($webdriver !== 0 && $webdriver !== 1 && $webdriver !== 0.0 && $webdriver !== 1.0) {
            return null;
        }

        $payload = [
            'c' => (int) $cores,
            'm' => (float) $memory,
            'p' => (float) $ratio,
            'r' => $renderer,
            't' => (int) $touch,
            'w' => (int) $webdriver,
        ];

        ksort($payload);

        return $payload;
    }
}
