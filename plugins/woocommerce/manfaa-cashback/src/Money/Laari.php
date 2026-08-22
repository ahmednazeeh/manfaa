<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Money;

/**
 * MVR amounts as integer laari, the only form money takes on the wire.
 *
 * WooCommerce carries cart math at up to 6 decimal places; the rule here
 * is "round to 2 dp FIRST, then ×100" — `(int) ($x * 100)` is forbidden
 * because 0.29 * 100 is 28.999999999999996.
 */
final class Laari
{
    public static function fromDecimal(float|int|string $amount): int
    {
        $rounded = round((float) $amount, 2);

        // String arithmetic to dodge binary float error on the ×100.
        $string = number_format($rounded, 2, '.', '');
        [$whole, $cents] = explode('.', $string);

        $sign = str_starts_with($whole, '-') ? -1 : 1;
        $whole = ltrim($whole, '-');

        return $sign * ((int) $whole * 100 + (int) $cents);
    }

    public static function toMvr(int $laari): string
    {
        $sign = $laari < 0 ? '-' : '';
        $laari = abs($laari);

        return $sign.intdiv($laari, 100).'.'.str_pad((string) ($laari % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The platform's rounding rule: ceiling at the basis point, per bucket. */
    public static function cashback(int $laari, int $rateBp): int
    {
        if ($laari <= 0 || $rateBp <= 0) {
            return 0;
        }

        return intdiv($laari * $rateBp + 9999, 10000);
    }
}
