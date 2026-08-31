<?php

namespace App\Services\Money;

use App\Enums\OutgoingPostbackKind;
use App\Models\Conversion;
use Closure;
use Illuminate\Support\Str;

/**
 * The publisher-facing macro set — docs/04-conversions-postbacks.md §2.
 *
 * This table is the public contract, reproduced verbatim in the developer docs.
 * Renaming or dropping a macro breaks every integration built against it.
 */
final class MacroResolver
{
    public static function resolve(string $template, Conversion $conversion, OutgoingPostbackKind $kind): string
    {
        $template = trim($template);
        $reversal = $kind === OutgoingPostbackKind::Reversal;
        $sign = $reversal ? '-' : '';

        // The signature covers the payout string exactly as it appears in the
        // URL, minus sign included, so a reversal cannot be replayed as a credit.
        $payout = $sign.Money::format($conversion->payout, Money::SCALE);

        $macros = [
            '{user_id}' => $conversion->end_user_id,
            '{trans_id}' => $conversion->trans_id,
            '{offer_id}' => (string) $conversion->offer?->external_id,
            '{offer_name}' => $conversion->offer_name,
            '{payout_usd}' => $payout,
            '{currency_amount}' => $sign.Money::format($conversion->currency_amount, 2),
            '{app_id}' => (string) $conversion->app?->app_id,
            '{country}' => (string) $conversion->country,
            '{ip}' => (string) $conversion->ip,
            '{status}' => $reversal ? 'reversed' : 'credited',
            '{timestamp}' => (string) now()->getTimestamp(),
            '{signature}' => self::sign($conversion, $payout),
        ];

        return str_replace(
            array_keys($macros),
            array_map(rawurlencode(...), array_values($macros)),
            $template
        );
    }

    public static function sign(Conversion $conversion, string $payoutUsd): string
    {
        return hash('sha256', $conversion->trans_id.$payoutUsd.(string) $conversion->app?->secret_key);
    }

    /**
     * A publisher callback is a URL *template*. Laravel's `url` rule rejects
     * `{user_id}` because the braces are not in its query alphabet — the same
     * reason the network wall URL is not `->url()`.
     */
    public static function isHttpsTemplate(string $template): bool
    {
        $template = trim($template);

        if ($template === '') {
            return true;
        }

        $sample = (string) preg_replace('/\{[a-z_]+\}/i', 'x', $template);

        return str_starts_with(strtolower($template), 'https://')
            && Str::isUrl($sample, ['https']);
    }

    public static function validateCallbackUrl(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || self::isHttpsTemplate((string) $value)) {
            return;
        }

        $fail('The callback URL must be a valid https URL. Placeholders like {user_id} are allowed.');
    }
}
