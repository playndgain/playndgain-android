<?php

namespace App\Services\Wall;

use App\Enums\DeviceType;

/**
 * Hand-rolled substring matching, the same register as
 * AbstractDriver::parseDevices. A UA-parser package is megabytes of regexes
 * maintained for browsers we never serve; the wall needs four device buckets,
 * an OS version to compare against `os_min_version`, and a browser name for
 * the click row.
 */
class DeviceDetector
{
    /**
     * @return array{device: DeviceType, os: ?string, os_version: ?string, browser: ?string}
     */
    public function detect(?string $userAgent): array
    {
        $ua = strtolower((string) $userAgent);

        return [
            'device' => $this->device($ua),
            'os' => $this->os($ua),
            'os_version' => $this->osVersion($ua),
            'browser' => $this->browser($ua),
        ];
    }

    private function device(string $ua): DeviceType
    {
        return match (true) {
            // Android first: every Android UA also carries "Linux".
            str_contains($ua, 'android') => DeviceType::Android,
            str_contains($ua, 'iphone'), str_contains($ua, 'ipad'), str_contains($ua, 'ipod') => DeviceType::Ios,
            // iPadOS 13+ reports itself as Macintosh and nothing in the string
            // separates it from a real Mac. Desktop is the safer wrong answer:
            // an iPad shown a desktop offer wastes a card, an iPad shown an
            // install offer it cannot complete costs a chargeback.
            str_contains($ua, 'windows'), str_contains($ua, 'macintosh'), str_contains($ua, 'mac os'),
            str_contains($ua, 'cros'), str_contains($ua, 'x11'), str_contains($ua, 'linux') => DeviceType::Desktop,
            default => DeviceType::Other,
        };
    }

    private function os(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'iphone os'), str_contains($ua, 'ipad'), str_contains($ua, 'cpu os') => 'iOS',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') => 'macOS',
            str_contains($ua, 'cros') => 'ChromeOS',
            str_contains($ua, 'linux') => 'Linux',
            default => null,
        };
    }

    /**
     * Dotted, so `version_compare` can put "10.1" above "9.9" — the string
     * comparison a naive filter would do gets that backwards.
     */
    private function osVersion(string $ua): ?string
    {
        foreach (['/android ([0-9._]+)/', '/(?:iphone os|cpu os) ([0-9._]+)/', '/windows nt ([0-9._]+)/', '/mac os x ([0-9._]+)/'] as $pattern) {
            if (preg_match($pattern, $ua, $matches) === 1) {
                return str_replace('_', '.', $matches[1]);
            }
        }

        return null;
    }

    private function browser(string $ua): ?string
    {
        return match (true) {
            // Edge, Opera and Samsung all claim to be Chrome; Chrome claims to
            // be Safari. Specific tokens have to be tested first.
            str_contains($ua, 'edg') => 'Edge',
            str_contains($ua, 'opr/'), str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'samsungbrowser') => 'Samsung Internet',
            str_contains($ua, 'firefox'), str_contains($ua, 'fxios') => 'Firefox',
            str_contains($ua, 'chrome'), str_contains($ua, 'crios') => 'Chrome',
            str_contains($ua, 'safari') => 'Safari',
            default => null,
        };
    }
}
