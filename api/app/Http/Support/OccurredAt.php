<?php

declare(strict_types=1);

namespace App\Http\Support;

use Carbon\CarbonImmutable;

/**
 * The wire grammar for `occurred_at` (PLAN §1 decision 2026-08-15).
 *
 * OPTIONAL everywhere it appears: omitted means NOW, which is what a till
 * posting a sale as it rings it up actually means.
 *
 * Two accepted shapes:
 *
 *  - ISO 8601 WITH an offset — "2026-08-15T13:45:00+05:00",
 *    "2026-08-15T08:45:00Z", "2026-08-15T13:45:00+0500". Unambiguous, so
 *    it is read exactly as sent.
 *  - A plain wall clock with NO offset — "2026-08-15 13:45:00" or
 *    "2026-08-15T13:45:00" — read as MALDIVES time (Indian/Maldives,
 *    +05:00), the business timezone.
 *
 * The offsetless form used to be REFUSED, because reading it as UTC would
 * freeze the rate at the wrong instant (five hours early) and silently
 * misdate every sale a till sent. Reading it as Maldives time solves that
 * problem honestly instead: it is the only sensible reading of a wall clock
 * from a Maldivian till, and it is what an integrator writing
 * `date('Y-m-d H:i:s')` on a local POS box means every time.
 *
 * Future-dated values are still refused (CreditRecorder, 422 future_dated)
 * and the backdated rule is unchanged.
 */
final class OccurredAt
{
    /**
     * ISO 8601 with an explicit offset. `P` takes "+05:00", `p` also takes
     * "Z", `O` takes "+0500".
     */
    private const array OFFSET_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:sp', 'Y-m-d\TH:i:sO'];

    /**
     * Offsetless wall clock, read as business-timezone local time.
     */
    private const array WALL_CLOCK_FORMATS = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s'];

    /**
     * The `date_format:` validation rule listing every accepted shape.
     */
    public static function rule(): string
    {
        return 'date_format:'.implode(',', [...self::OFFSET_FORMATS, ...self::WALL_CLOCK_FORMATS]);
    }

    /**
     * Parses a validated value to a UTC instant. An offsetless wall clock
     * is interpreted in the business timezone; anything else carries its
     * own offset and is simply normalised to UTC.
     */
    public static function parse(string $value): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            // A wall clock plus a timezone is one instant — Carbon applies
            // the zone to the parsed local time, never shifts the digits.
            return CarbonImmutable::parse(str_replace('T', ' ', $value), self::businessTimezone())->utc();
        }

        return CarbonImmutable::parse($value)->utc();
    }

    /**
     * The value as sent, or NOW when the field was omitted — the one place
     * the "optional means now" rule lives.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromRequest(array $validated, string $key = 'occurred_at'): CarbonImmutable
    {
        $value = $validated[$key] ?? null;

        return is_string($value) && $value !== ''
            ? self::parse($value)
            : CarbonImmutable::now('UTC');
    }

    private static function businessTimezone(): string
    {
        return (string) config('app.business_timezone', 'Indian/Maldives');
    }
}
