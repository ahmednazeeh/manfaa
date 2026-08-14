<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use Carbon\CarbonImmutable;

/**
 * The §13 payout calendar, from the customer's point of view: rewards
 * confirmed by the 24th 23:59 (business time) pay out in the 25th–month-end
 * window of the SAME month; anything confirmed after the cutoff rolls to
 * next month's window. next() answers "when would cashback confirming right
 * now be paid?" — which is what the balance screen promises.
 */
final class PayoutWindow
{
    /** Cutoff day of month, ending 23:59:59 business time (§13). */
    public const int CUTOFF_DAY = 24;

    /** First day of the payout window. */
    public const int WINDOW_START_DAY = 25;

    /**
     * @return array{starts_at: string, ends_at: string} business-timezone dates
     */
    public static function next(CarbonImmutable $now, string $businessTimezone): array
    {
        $local = $now->setTimezone($businessTimezone);
        $cutoff = $local->setDay(self::CUTOFF_DAY)->endOfDay();

        $month = $local->isAfter($cutoff)
            ? $local->startOfMonth()->addMonthsNoOverflow(1)
            : $local->startOfMonth();

        return [
            'starts_at' => $month->setDay(self::WINDOW_START_DAY)->toDateString(),
            'ends_at' => $month->endOfMonth()->toDateString(),
        ];
    }
}
