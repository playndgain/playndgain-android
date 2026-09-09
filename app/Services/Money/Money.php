<?php

namespace App\Services\Money;

/**
 * Every arithmetic step on a money column goes through here.
 *
 * Payouts of $0.0037 are normal in this business and they accumulate across
 * millions of rows. Float arithmetic drifts at the sixth decimal place and the
 * drift only surfaces months later, in a reconciliation nobody can explain.
 */
final class Money
{
    /** `decimal(14,6)` — revenue, payout, every ledger amount. */
    public const SCALE = 6;

    /** `decimal(14,4)` — publisher currency amounts. */
    public const CURRENCY_SCALE = 4;

    /** Publisher's cut of what the network paid us. */
    public static function share(string|float|int $revenue, string|float|int $revShare): string
    {
        $exact = bcdiv(bcmul(self::decimal($revenue), self::decimal($revShare), 18), '100', 18);

        return self::round($exact, self::SCALE);
    }

    /** Payout converted into the app's own currency. */
    public static function currency(string|float|int $payout, string|float|int $rate): string
    {
        return self::round(bcmul(self::decimal($payout), self::decimal($rate), 18), self::CURRENCY_SCALE);
    }

    public static function format(string|float|int $amount, int $scale = self::SCALE): string
    {
        return self::round(self::decimal($amount), $scale);
    }

    /**
     * "$1,234.5679" for the panels — sign before the symbol, always at least
     * cents, and the remaining decimals only while they carry a digit:
     * `$0.0037` keeps its precision, `$235.718000` does not drag four zeros
     * behind it. Nothing is rounded away — trailing zeros are the only thing
     * dropped. The one formatter every money figure a publisher sees goes
     * through, so two pages cannot show the same number two ways — design.md §3.
     */
    public static function display(string|float|int $amount): string
    {
        $formatted = self::format($amount);
        $negative = str_starts_with($formatted, '-');
        [$whole, $fraction] = explode('.', ltrim($formatted, '-'), 2);

        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return ($negative ? '-$' : '$').number_format((int) $whole).'.'.$fraction;
    }

    public static function negate(string|float|int $amount, int $scale = self::SCALE): string
    {
        return bcsub('0', self::decimal($amount), $scale);
    }

    /** bcmath truncates; money rounds half away from zero. */
    public static function round(string $amount, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($amount, '0', $scale + 12) < 0
            ? bcsub($amount, $half, $scale)
            : bcadd($amount, $half, $scale);
    }

    /**
     * A float reaching this class is already suspect, but network payouts
     * arrive as one on the DTO. `%F` is locale-independent and never emits
     * scientific notation, which bcmath would reject.
     */
    private static function decimal(string|float|int $amount): string
    {
        return is_float($amount) ? sprintf('%.18F', $amount) : (string) $amount;
    }
}
