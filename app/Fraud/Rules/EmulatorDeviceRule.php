<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * An Android emulator announcing itself in its own User-Agent — the spec's §5.
 *
 * `DeviceDetector` reads the UA for four device buckets, an OS version and a
 * browser name, and throws the build string away. A stock emulator puts its
 * identity in exactly that discarded part: `sdk_gphone`, `Android SDK built for
 * x86`, `vbox86`, and the build markers of the desktop players an install farm
 * actually runs on.
 *
 * The cheapest layer of emulator detection and the one with no cost at all — no
 * request, no client code, no consent surface, no bundle weight. It catches
 * stock configurations, which is most of them, and nothing else. A farm that
 * edits its build string walks past this rule; that is what the client-signal
 * layer is for.
 *
 * **The signature list is the risk.** `WallGate::protectionBlock()` runs every
 * rule whose action is `block`, so this is live at render on every impression
 * the moment it is enabled, and one over-broad token empties the wall for a
 * whole device class with nothing to show it happened. Only unambiguous strings
 * are seeded. Generic tokens — a bare `generic_x86`, anything plausible inside a
 * real OEM build string — are candidates to be validated against real
 * `offer_clicks.user_agent` values first, and added through the config from the
 * panel rather than shipped here.
 */
class EmulatorDeviceRule implements RenderGateRule
{
    /**
     * The fallback when a rule row carries no list. Kept in step with
     * `FraudRuleSeeder`, which is the tunable copy — this one only covers a row
     * whose config was emptied.
     *
     * A bare `emulator` is deliberately **not** here, and neither is any other
     * standalone English word. It reads like the strongest possible signal and
     * is exactly the over-broad token this rule's docblock warns about: it is a
     * substring, so it matches any build string that merely contains the word —
     * WebView wrappers and QA browsers among them — and a false positive here
     * empties the wall silently. `android sdk built for` and `sdk_gphone`
     * already cover the standard AVD images it would have caught. It is a
     * candidate for the config once real user agents can be checked against it,
     * which is the process the class docblock describes.
     *
     * @var list<string>
     */
    private const DEFAULT_SIGNATURES = [
        'sdk_gphone',
        'android sdk built for',
        'vbox86',
        'google_sdk',
        'bluestacks',
        'nox player',
        'ldplayer',
        'memu',
    ];

    public static function key(): string
    {
        return 'emulator_device';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    /**
     * The generic screen, never a named one.
     *
     * docs/05 §3 allows naming the category only where the information is
     * asymmetric in our favour — a VPN user does not know their VPN is the
     * problem, and one toggle turns them into a served user. Nothing about that
     * transfers here: nobody reaches an offerwall on an emulator by accident,
     * so there is no honest user for a specific message to help, and naming the
     * signal would only tell a farm which string to change.
     */
    public function noticeKey(RuleResult $result): ?string
    {
        return 'blocked_unavailable';
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $ua = $context->userAgent;

        // `ua_anomaly` already owns the missing-UA case. Two rules blocking on
        // one fact is two fraud_events for one decision.
        if (blank($ua)) {
            return RuleResult::pass();
        }

        $haystack = strtolower($ua);

        foreach ($this->signatures($config) as $signature) {
            if ($signature !== '' && str_contains($haystack, $signature)) {
                return RuleResult::trigger('user agent carries an emulator build signature', [
                    'signature' => $signature,
                    'user_agent' => $ua,
                    'device' => $context->device,
                    'os' => $context->os,
                ]);
            }
        }

        return RuleResult::pass();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function signatures(array $config): array
    {
        $configured = $config['signatures'] ?? null;

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_SIGNATURES;
        }

        return array_values(array_map(
            static fn (mixed $signature): string => strtolower(trim((string) $signature)),
            array_filter($configured, 'is_scalar'),
        ));
    }
}
