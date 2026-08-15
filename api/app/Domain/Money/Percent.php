<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * The ONE translation between the wire (2-decimal percent) and the engine
 * (integer basis points) — PLAN §1 "API wire format".
 *
 * `rate_bp` / `fee_bp` never appear in a request or a response; the wire
 * carries `cashback_rate_percent` / `platform_fee_percent` as 2-decimal
 * STRINGS ("2.00", "0.75", "12.50"), the same idiom `cashback_mvr` already
 * uses. Everything inside — storage, the ledger, every computation — stays
 * integer basis points. This class is the only place the two meet, and it
 * is pure integer/string math: NO FLOAT ARITHMETIC EVER TOUCHES A RATE.
 *
 * ## How a JSON number is converted without losing a digit
 *
 * A JSON body may send the percent as a number (`2`, `2.5`) rather than a
 * string. PHP hands us a float for `2.5`, and multiplying a float by 100 is
 * exactly the mistake the money law forbids (2.5 * 100 = 249.99999999999997
 * on some values, and `(int)` truncation then silently loses a laari).
 *
 * Instead the float is PROVED to be a 2-decimal number before it is read as
 * one: it is formatted to exactly two decimals with `sprintf('%.2F', …)`
 * (locale-independent, never an exponent) and that text is accepted only if
 * casting it back yields the IDENTICAL double. The equality is the proof —
 * if `(float) "2.50" === $input` then $input IS the double for 2.50, and
 * reading it as 250 bp cannot have lost a digit. `2.555` fails the check
 * (neither "2.55" nor "2.56" round-trips to it) and is refused rather than
 * silently rounded. The accepted text is then split on the decimal point
 * and read with integer math. `(string)` is deliberately not used: its
 * `precision=14` ini setting rounds, which is a digit-losing conversion.
 *
 * Callers that would rather not rely on the round-trip at all can read the
 * RAW request body value (a JSON number arrives as text on the wire) and
 * pass the string; both paths meet here and agree.
 */
final class Percent
{
    /**
     * The absolute structural bound of anything expressed as a rate here:
     * the §4 cashback ceiling (20.00%). Nothing on the wire — cashback
     * rate, platform fee, tier band edge — may exceed it.
     */
    public const int MAX_BP = Rate::MAX_CASHBACK_BP;

    /**
     * A customer cashback rate: the §4 range 50–2000 bp (0.50%–20.00%).
     * Accepts "2", "2.5", "2.50", 2, 2.5 — a string or a JSON number with
     * at most 2 decimals.
     *
     * @throws InvalidArgumentException on a malformed, negative, over-precise or out-of-range value
     */
    public static function toBasisPoints(string|int|float $input): int
    {
        return self::bounded($input, Rate::MIN_CASHBACK_BP, Rate::MAX_CASHBACK_BP);
    }

    /**
     * A platform fee rate: 1–2000 bp. Same wire grammar as
     * toBasisPoints(); only the bounds differ (a fee may sit below 0.50%,
     * e.g. the 0.25% first tier).
     *
     * @throws InvalidArgumentException
     */
    public static function toFeeBasisPoints(string|int|float $input): int
    {
        return self::bounded($input, Rate::MIN_FEE_BP, Rate::MAX_CASHBACK_BP);
    }

    /**
     * A rate with bounds of its own rather than one of the two §4 ranges —
     * a platform setting whose floor is 0 because zero switches the
     * incentive off (PlatformConfig::PERCENT_KEYS). Same wire grammar.
     *
     * @throws InvalidArgumentException
     */
    public static function toBasisPointsWithin(string|int|float $input, int $minBp, int $maxBp): int
    {
        return self::bounded($input, $minBp, $maxBp);
    }

    /**
     * parse() plus an inclusive basis-point range, reported in percent —
     * the caller's own bounds (cashback 50–2000, fee 1–2000).
     *
     * @throws InvalidArgumentException
     */
    private static function bounded(string|int|float $input, int $minBp, int $maxBp): int
    {
        $bp = self::parse($input);

        if ($bp < $minBp || $bp > $maxBp) {
            throw new InvalidArgumentException(sprintf(
                'A rate of %s%% is outside the permitted %s%%-%s%% range.',
                self::format($bp),
                self::format($minBp),
                self::format($maxBp),
            ));
        }

        return $bp;
    }

    /**
     * Basis points as the wire's 2-decimal percent string. Pure integer
     * math: 200 -> "2.00", 75 -> "0.75", 1250 -> "12.50", 0 -> "0.00".
     */
    public static function format(int $bp): string
    {
        if ($bp < 0) {
            throw new InvalidArgumentException(sprintf('A rate may not be negative, got %d bp.', $bp));
        }

        return sprintf('%d.%02d', intdiv($bp, 100), $bp % 100);
    }

    /**
     * format() for the many nullable rate fields (an excluded product
     * category, an unpriced legacy rate, a settlement with no discount).
     */
    public static function formatOrNull(?int $bp): ?string
    {
        return $bp === null ? null : self::format($bp);
    }

    /**
     * A DIFFERENCE between two rates, which may legitimately be negative:
     * 26 -> "0.26", -26 -> "-0.26". Same integer math, one sign in front.
     */
    public static function formatDelta(int $bp): string
    {
        return ($bp < 0 ? '-' : '').self::format(abs($bp));
    }

    /**
     * True when $input is a wire-legal percent within [$minBp, $maxBp] —
     * the predicate the validation rule asks, so a bad value becomes a
     * field error instead of an exception.
     */
    public static function isValid(mixed $input, int $minBp, int $maxBp): bool
    {
        if (! is_string($input) && ! is_int($input) && ! is_float($input)) {
            return false;
        }

        try {
            $bp = self::parse($input);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $bp >= $minBp && $bp <= $maxBp;
    }

    /**
     * The parser: wire percent -> basis points, integer math only.
     *
     * Rejects negatives, non-numeric text, more than 2 decimals, and
     * anything above the structural 20.00% ceiling. The result is bounded
     * further by the caller (cashback vs fee ranges).
     *
     * @throws InvalidArgumentException
     */
    private static function parse(string|int|float $input): int
    {
        $text = self::asDecimalString($input);

        // Deliberately strict: no sign, no exponent, no whitespace, no
        // thousands separators, at most 2 decimal places. 5 integer digits
        // is far above the 20% ceiling and keeps the multiplication in int
        // range on any platform.
        if (preg_match('/^(\d{1,5})(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a percent with at most 2 decimal places.',
                is_float($input) ? $text : (string) $input,
            ));
        }

        $whole = (int) $matches[1];
        $fraction = isset($matches[2]) ? (int) str_pad($matches[2], 2, '0') : 0;

        $bp = $whole * 100 + $fraction;

        if ($bp > self::MAX_BP) {
            throw new InvalidArgumentException(sprintf(
                'A rate may not exceed %s%%, got %s%%.',
                self::format(self::MAX_BP),
                $text,
            ));
        }

        return $bp;
    }

    /**
     * The decimal text of the input, without ever rounding it:
     * - a string is already the caller's digits;
     * - an int is exact;
     * - a float is proved to BE a 2-decimal number before it is read as
     *   one (see below). NAN/INF have no decimal string and are refused.
     */
    private static function asDecimalString(string|int|float $input): string
    {
        if (is_string($input)) {
            return $input;
        }

        if (is_int($input)) {
            return (string) $input;
        }

        if (! is_finite($input)) {
            throw new InvalidArgumentException('A rate must be a finite number.');
        }

        // THE FLOAT RULE. Format to exactly 2 decimals (`%.2F` is
        // locale-independent and never uses an exponent) and accept that
        // text ONLY if it parses back to the IDENTICAL double. That
        // equality is the proof: if `(float) "2.50" === $input`, then
        // $input is the double for 2.50 and reading it as 250 bp loses
        // nothing. A value that needed a third decimal to be itself fails
        // the check — 2.555 formats to "2.55"/"2.56", neither of which
        // round-trips — and is refused rather than silently rounded.
        //
        // This holds whatever the host's precision/serialize_precision ini
        // settings are, and no float is ever multiplied.
        $fixed = sprintf('%.2F', $input);

        if ((float) $fixed === $input) {
            return $fixed;
        }

        // Not a 2-decimal value: hand the grammar the shortest text that
        // does round-trip, purely so the refusal message shows the caller
        // what they actually sent.
        $encoded = json_encode($input);

        return is_string($encoded) ? $encoded : $fixed;
    }
}
