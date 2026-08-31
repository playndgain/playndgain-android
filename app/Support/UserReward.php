<?php

namespace App\Support;

use App\Models\App;
use App\Services\Money\Money;

/**
 * What the end user is paid in the app's own currency.
 *
 * Same arithmetic as the wall and as `conversions.currency_amount`:
 * `payout × rev_share × currency_rate`. One formatter so the catalogue, the
 * events slide-over and the credit cannot quote three different numbers for
 * the same step.
 */
final class UserReward
{
    /**
     * Admin catalogue has no app in scope. The rest of the product already
     * quotes this pair as the worked example (docs/08 §2, registration).
     */
    public const PREVIEW_RATE = '1000';

    public const PREVIEW_CURRENCY = 'Coins';

    public static function amount(string|float|int $grossUsd, string|float|int $revShare, string|float|int $rate): float
    {
        return (float) Money::currency(
            Money::share($grossUsd, Money::format($revShare, 2)),
            $rate,
        );
    }

    public static function label(string|float|int $grossUsd, string|float|int $revShare, string|float|int $rate, string $currencyName): string
    {
        $amount = self::amount($grossUsd, $revShare, $rate);

        return self::format($amount).' '.$currencyName;
    }

    public static function forApp(string|float|int $grossUsd, string|float|int $revShare, App $app): string
    {
        return self::label($grossUsd, $revShare, $app->currency_rate, $app->currency_name);
    }

    public static function format(float $amount): string
    {
        return number_format($amount, $amount < 10 ? 2 : 0);
    }
}
