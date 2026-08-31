<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value platform settings. Cached as one array — there are a couple of
 * dozen of them and they are read on nearly every request.
 */
class Setting extends Model
{
    public const CACHE_KEY = 'settings';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * @return array<string, string|null>
     */
    public static function cached(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->pluck('value', 'key')->all()
        );
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::cached()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Read in two places now — the wall's consent gate and the IP chain's
     * refusal to send an unconsented address to a third party. One decoder, so
     * the two cannot disagree about what the setting holds.
     *
     * @return list<string>
     */
    public static function consentCountries(): array
    {
        $raw = static::get('consent_required_countries');
        $countries = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($countries) ? array_values(array_filter($countries, 'is_string')) : [];
    }
}
