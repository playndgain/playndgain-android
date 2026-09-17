<?php

namespace App\Networks\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Last-resort offer art when a feed ships a store listing and no creative.
 *
 * Swaarm (and other platforms) leave `icons` and `creatives_url` empty on CPA
 * rows that still carry `app_id` / `preview_url`. The wall and the catalogue
 * then show the initials chip. The iTunes lookup is the same official endpoint
 * the demo-art command already uses; Play Store is only the `og:image` on the
 * public listing, for Android-only apps that iTunes does not know.
 *
 * Failures stay null — a missing icon is better than a sync that dies because
 * Apple or Google was slow.
 */
final class StoreIcon
{
    /** @var array<string, ?string> */
    private array $resolved = [];

    public function resolve(mixed $appId, mixed $previewUrl): ?string
    {
        $appId = is_scalar($appId) ? trim((string) $appId) : '';
        $previewUrl = is_scalar($previewUrl) ? trim((string) $previewUrl) : '';

        $appleId = $this->appleId($appId, $previewUrl);

        if ($appleId !== null) {
            return $this->itunes(['id' => $appleId]);
        }

        $bundle = $this->bundleId($appId, $previewUrl);

        if ($bundle === null) {
            return null;
        }

        return $this->itunes(['bundleId' => $bundle])
            ?? $this->play($bundle);
    }

    private function appleId(string $appId, string $previewUrl): ?string
    {
        if (preg_match('/^\d+$/', $appId) === 1) {
            return $appId;
        }

        if (preg_match('#apps\.apple\.com/[^?]*?/id(\d+)#', $previewUrl, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function bundleId(string $appId, string $previewUrl): ?string
    {
        if ($this->isBundle($appId)) {
            return $appId;
        }

        $host = parse_url($previewUrl, PHP_URL_HOST);
        $query = [];
        parse_str((string) parse_url($previewUrl, PHP_URL_QUERY), $query);
        $package = is_string($query['id'] ?? null) ? $query['id'] : '';

        if (is_string($host) && str_contains($host, 'play.google.com') && $this->isBundle($package)) {
            return $package;
        }

        return null;
    }

    private function isBundle(string $value): bool
    {
        return preg_match('/^[A-Za-z][\w]*(\.[A-Za-z][\w]*)+$/', $value) === 1;
    }

    /** @param  array<string, string>  $query */
    private function itunes(array $query): ?string
    {
        $key = 'itunes:'.http_build_query($query);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        try {
            $response = Http::timeout(8)->get('https://itunes.apple.com/lookup', $query);
        } catch (Throwable) {
            return $this->resolved[$key] = null;
        }

        if (! $response->successful()) {
            return $this->resolved[$key] = null;
        }

        $art = $response->json('results.0.artworkUrl512')
            ?? $response->json('results.0.artworkUrl100');

        return $this->resolved[$key] = $this->httpsUrl($art);
    }

    private function play(string $package): ?string
    {
        $key = 'play:'.$package;

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        try {
            $response = Http::timeout(8)
                ->withUserAgent('Mozilla/5.0 (compatible; PlayndgainBot/1.0)')
                ->get('https://play.google.com/store/apps/details', [
                    'id' => $package,
                    'hl' => 'en',
                    'gl' => 'US',
                ]);
        } catch (Throwable) {
            return $this->resolved[$key] = null;
        }

        if (! $response->successful()) {
            return $this->resolved[$key] = null;
        }

        if (preg_match('/property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/', $response->body(), $match) !== 1
            && preg_match('/content=["\']([^"\']+)["\']\s+property=["\']og:image["\']/', $response->body(), $match) !== 1) {
            return $this->resolved[$key] = null;
        }

        return $this->resolved[$key] = $this->httpsUrl($match[1]);
    }

    private function httpsUrl(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') ? $value : null;
    }
}
