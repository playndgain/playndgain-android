<?php

namespace App\Services\Wall;

use App\Enums\DeviceType;
use App\Fraud\FraudContext;
use App\Fraud\IpVerdict;
use App\Models\App;
use App\Models\Offer;
use App\Support\Contrast;

/**
 * Everything resolved from one wall or click request, once. Steps 1–6 of the
 * render pipeline produce it; nothing after step 6 re-reads the request.
 */
final class WallContext
{
    /** v1 ships `en` and `ar` (docs/03 §1). Anything else falls back to `en`. */
    public const LOCALES = ['en', 'ar'];

    /**
     * Falls back to the brand's own Midnight palette so an app that never
     * opened the appearance tab still looks like a product. The values are
     * the design board's wall card: page, row, text and the gold accent.
     *
     * @var array<string, string|bool>
     */
    public const DEFAULT_THEME = [
        'primary' => '#FFB020',
        'background' => '#0A0B14',
        'card' => '#11131F',
        'text' => '#F4F5FA',
        'radius' => '14px',
        'dark' => true,
    ];

    /**
     * What `?theme=light` renders on an app that never chose a background:
     * the site's paper surfaces with the app's own primary kept.
     *
     * @var array<string, string>
     */
    public const LIGHT_SURFACES = [
        'background' => '#F5F5FA',
        'card' => '#FFFFFF',
        'text' => '#0C0D1A',
    ];

    /**
     * @param  array<string, string|bool>  $theme  Already sanitised.
     */
    public function __construct(
        public readonly App $app,
        public readonly string $endUserId,
        public readonly string $ip,
        public readonly ?string $country,
        /**
         * The full IP verdict `country` was taken from. Carried so
         * `WallGate::protectionBlock()` can hand it to the `FraudContext` it
         * builds for the render gate — the five IP-family rules there prefer
         * it over calling `IpIntelligence::inspect()` again
         * (`FraudContext::$verdict`), so a render that evaluates several
         * gate rules resolves the verdict once rather than once per rule.
         * The click path is a separate request in every sense that matters
         * and builds its own verdict-less `FraudContext`
         * (`fraudContext()` below), which is correct: an IP that changed
         * between render and click deserves a fresh lookup, not this one.
         */
        public readonly IpVerdict $verdict,
        public readonly DeviceType $device,
        public readonly ?string $os,
        public readonly ?string $osVersion,
        public readonly ?string $browser,
        public readonly ?string $userAgent,
        public readonly ?string $referrer,
        public readonly string $locale,
        public readonly array $theme,
        public readonly bool $testMode,
        public readonly ?string $balance,
        /**
         * Whether `country` came from `?country=XX` rather than from the IP.
         *
         * It exists so a click can be marked as test earnings. The override is
         * only honoured in test mode, but test mode is a publisher-editable
         * toggle on a live app, and anyone holding the wall URL can append the
         * parameter — so without this, leaving the toggle on turns every
         * geo-targeted offer into one that converts for real from any country
         * on earth. Those are exactly the conversions a network charges back.
         */
        public readonly bool $countryOverridden = false,
        /**
         * Which native SDK sent this request, if any: `android` or `ios`, its
         * version, and the device model. Logging only. Never an input to
         * targeting, because a client can send anything here.
         */
        public readonly ?string $sdkPlatform = null,
        public readonly ?string $sdkVersion = null,
        public readonly ?string $deviceModel = null,
        /**
         * Publisher sub-ids, echoed on the postback as {sub1}..{sub5}.
         *
         * @var array<string, string> keyed sub1..sub5, only the valid ones
         */
        public readonly array $subs = [],
        /**
         * The SDK's build string (`Build.*` on Android, `utsname` on iOS).
         *
         * It exists for one rule. An SDK click opens in the system browser,
         * whose reduced User-Agent carries no model or build, so
         * `emulator_device` would see nothing an iframe click shows it. The
         * hint is incriminating only: `EmulatorDeviceRule` scans it *in
         * addition to* the User-Agent, so a forged clean hint clears nothing.
         */
        public readonly ?string $deviceHint = null,
    ) {}

    public function dir(): string
    {
        return $this->locale === 'ar' ? 'rtl' : 'ltr';
    }

    public function isDark(): bool
    {
        return (bool) ($this->theme['dark'] ?? true);
    }

    /**
     * Custom properties for `:root`. No per-app CSS file and no per-app build —
     * docs/03 §5.
     *
     * @return array<string, string>
     */
    public function cssVars(): array
    {
        return self::vars($this->theme);
    }

    /**
     * For the pages that render before an app is resolved — 404, unavailable.
     *
     * @return array<string, string>
     */
    public static function defaultVars(): array
    {
        return self::vars(self::DEFAULT_THEME);
    }

    /**
     * @param  array<string, string|bool>  $theme
     * @return array<string, string>
     */
    public static function vars(array $theme): array
    {
        return [
            '--wall-primary' => (string) $theme['primary'],
            // The one derived value CSS cannot compute for itself: what is
            // readable *on* the primary. The wall's own chrome is monochrome,
            // but the pair stays in the contract — any surface that does fill
            // with the accent gets a legible foreground, never a coin flip
            // against a colour the publisher chose. See Contrast::on().
            '--wall-on-primary' => Contrast::on((string) $theme['primary']),
            '--wall-bg' => (string) $theme['background'],
            '--wall-card' => (string) $theme['card'],
            '--wall-text' => (string) $theme['text'],
            '--wall-radius' => (string) $theme['radius'],
        ];
    }

    /** The URL every card and the consent form echo back. */
    public function params(): string
    {
        $theme = request()->input('theme');

        return http_build_query(array_filter([
            'appid' => (string) $this->app->app_id,
            'userid' => $this->endUserId,
            'sig' => request()->input('sig'),
            'lang' => $this->locale,
            'country' => $this->testMode ? $this->country : null,
            // Carried forward like `lang`: the header balance pill and the
            // menu's appearance toggle are per-load publisher input, and a tab
            // switch or a sort submit that dropped them would reset both.
            'theme' => in_array($theme, ['light', 'dark'], true) ? $theme : null,
            'balance' => $this->balance,
            // Carried forward so the click a native SDK opens in the system
            // browser still says which SDK, and still carries the build
            // evidence the emulator rule needs at click time.
            'sdk' => $this->sdkPlatform,
            'sdk_v' => $this->sdkPlatform !== null ? $this->sdkVersion : null,
            'model' => $this->sdkPlatform !== null ? $this->deviceModel : null,
            'dh' => $this->sdkPlatform !== null ? $this->deviceHint : null,
            ...$this->subs,
        ], fn (?string $value) => $value !== null && $value !== ''));
    }

    public function fraudContext(?Offer $offer = null): FraudContext
    {
        return new FraudContext(
            app: $this->app,
            endUserId: $this->endUserId,
            ip: $this->ip,
            country: $this->country,
            userAgent: $this->userAgent,
            device: $this->device->value,
            os: $this->os,
            browser: $this->browser,
            referrer: $this->referrer,
            offer: $offer,
            deviceHint: $this->deviceHint,
        );
    }

    /**
     * `apps.theme` is publisher input landing in a `<style>` block, so every
     * value is whitelisted rather than escaped — an escaped `}` still ends the
     * rule, and one bad value should cost a colour, not the page.
     *
     * @param  mixed  $raw
     * @return array<string, string|bool>
     */
    public static function sanitiseTheme($raw, ?string $override = null): array
    {
        $theme = self::DEFAULT_THEME;
        $raw = is_array($raw) ? $raw : [];

        foreach (['primary', 'background', 'card', 'text'] as $key) {
            $value = $raw[$key] ?? null;

            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1) {
                $theme[$key] = $value;
            }
        }

        if (isset($raw['radius']) && is_string($raw['radius']) && preg_match('/^\d{1,3}(px|rem|em)$/', $raw['radius']) === 1) {
            $theme['radius'] = $raw['radius'];
        }

        if (isset($raw['dark'])) {
            $theme['dark'] = (bool) $raw['dark'];
        }

        // A light wall whose surfaces are still the dark defaults — the flag
        // was switched but the colours never were, or the card, which has no
        // picker, was never set at all — takes the paper surfaces. A surface
        // the publisher actually chose is kept, whichever mode it is in.
        if (! $theme['dark']) {
            foreach (self::LIGHT_SURFACES as $key => $light) {
                if ($theme[$key] === self::DEFAULT_THEME[$key]) {
                    $theme[$key] = $light;
                }
            }
        }

        // The `theme` query parameter is a per-load override for a publisher
        // whose own UI has a light/dark toggle. It has to swap the surfaces,
        // not just the `dark` flag: a paper palette with `color-scheme: dark`
        // is a white page and a black scrollbar, which is how the menu's
        // Dark mode used to look like it did nothing.
        if ($override === 'light') {
            $theme['dark'] = false;

            foreach (self::LIGHT_SURFACES as $key => $light) {
                $theme[$key] = $light;
            }
        }

        if ($override === 'dark') {
            $theme['dark'] = true;
            $theme['background'] = self::DEFAULT_THEME['background'];
            $theme['card'] = self::DEFAULT_THEME['card'];
            $theme['text'] = self::DEFAULT_THEME['text'];
        }

        return $theme;
    }
}
