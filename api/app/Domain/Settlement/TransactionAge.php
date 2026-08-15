<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use Carbon\CarbonImmutable;

/**
 * How old a payable transaction is, in WHOLE days since its settlement clock
 * started — the one definition shared by the merchant's age buckets, the
 * settlement picker's filter presets and the prompt-payment discount, so the
 * three can never disagree about whether a line is "10 days old".
 *
 * §13: timestamps are stored UTC, business rules are evaluated in UTC+5.
 * Both instants are moved into the business timezone and truncated to the
 * start of their day before subtracting, so the answer is a calendar-day
 * count a merchant can verify against their own clock — never a 24-hour
 * quotient that flips at some hour of the afternoon.
 */
final class TransactionAge
{
    /**
     * Whole days elapsed from $clockStartAt to $at, never negative.
     *
     * A null clock start means the clock never started; age is measured FROM
     * that instant, so nothing has elapsed and the answer is 0. (A payable
     * row with a null clock_start_at is a defect in its own right — §13b —
     * and is invisible to the escalation ladder; it is not this function's
     * job to invent a start date for it.)
     */
    public static function days(?CarbonImmutable $clockStartAt, CarbonImmutable $at, string $timezone): int
    {
        if ($clockStartAt === null) {
            return 0;
        }

        $start = $clockStartAt->setTimezone($timezone)->startOfDay();
        $today = $at->setTimezone($timezone)->startOfDay();

        return max(0, (int) $start->diffInDays($today));
    }

    /**
     * The business timezone every age is evaluated in (§13).
     */
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Indian/Maldives');
    }
}
