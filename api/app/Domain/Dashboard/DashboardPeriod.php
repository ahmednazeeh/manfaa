<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * The dashboard's window, and the window before it.
 *
 * Deliberately built out of {@see ReportPeriod} rather than beside it: the
 * dashboard's whole reason to exist is that it must never disagree with the
 * Reports page, and "which side of midnight does this sale fall on" is the
 * first place two screens drift apart. Same half-open [start, end) range,
 * same business-timezone dates (§13: Indian/Maldives, never the browser's
 * and never UTC), same 366-day ceiling.
 */
final class DashboardPeriod
{
    /**
     * The default: the business month IN PROGRESS — the 1st to today, not
     * the 1st to the 31st. A month-to-date figure beside a whole previous
     * month would be a comparison nobody could read, which is why
     * {@see self::preceding()} matches this window's LENGTH rather than
     * naming the calendar month before it.
     */
    public static function currentMonth(): ReportPeriod
    {
        $timezone = ReportPeriod::businessTimezone();
        $today = CarbonImmutable::now($timezone);

        return ReportPeriod::of(
            $today->startOfMonth()->format('Y-m-d'),
            $today->format('Y-m-d'),
            $timezone,
        );
    }

    /**
     * The equal-length window ending the day before this one begins: 25 days
     * of August answered by the 25 days that ran up to 31 July. Adjacent, so
     * no day is counted twice and none is skipped.
     */
    public static function preceding(ReportPeriod $period): ReportPeriod
    {
        $days = $period->days();

        return ReportPeriod::of(
            $period->from->subDays($days)->format('Y-m-d'),
            $period->from->subDay()->format('Y-m-d'),
            $period->timezone,
        );
    }
}
