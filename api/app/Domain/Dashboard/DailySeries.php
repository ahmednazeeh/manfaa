<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportPeriod;

/**
 * THE CHART: one row per day of the period, every day of it.
 *
 * ZERO-FILLED, and that is not cosmetic. A chart drawn from sparse rows
 * draws a straight line across a quiet week and makes it look like trade;
 * the days with nothing in them are data. Every date between `from` and
 * `to` inclusive appears exactly once, in order, whether or not anything
 * happened on it.
 *
 * BUSINESS DAYS (§13, Indian/Maldives). The three source queries bucket on
 * `(column AT TIME ZONE :business)::date` and the fill walks the same
 * calendar, so a bar labelled "4 August" holds the Maldivian 4th — the same
 * day the Reports page would put those sales in — rather than a UTC day
 * that ends at 05:00 local.
 *
 * THE FOUR NUMBERS, and where each comes from:
 *
 *   cashback_laari     accrued by the SALE's date (CashbackReport)
 *   fee_accrued_laari  the platform fee on those same sales, accrued with
 *                      them. Named `accrued` on purpose: it is NOT the money
 *                      panel's `platform_fees_net_laari`, which is what the
 *                      LEDGER recognised in the period after discounts. Two
 *                      honest numbers about fees, and the names say which is
 *                      which rather than letting a reader assume they should
 *                      tie.
 *   collected_laari    what merchants have paid on the batches raised that
 *                      day (CashbackReport's settlement scope)
 *   paid_out_laari     cashback whose PAID event landed that day (PayoutReport)
 *
 * The other three DO tie: summed over the period they equal the money
 * panel's cashback_generated, collected_from_merchants and
 * paid_out_to_customers, and DashboardSeriesTest asserts it.
 *
 * Three queries, all grouped in the database. Nothing is chunked and nothing
 * is walked row by row: the result is bounded by the period's length, which
 * ReportPeriod caps at 366 days.
 */
final class DailySeries
{
    /**
     * @return list<array{date: string, cashback_laari: int, fee_accrued_laari: int, collected_laari: int, paid_out_laari: int}>
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $sales = new CashbackReport($period);
        $cashback = $sales->dailyTotals();
        $collected = $sales->dailyCollected();
        $paidOut = (new PayoutReport($period))->dailyPaid();

        $series = [];

        for ($day = $period->from; $day->lessThanOrEqualTo($period->to); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');

            $series[] = [
                'date' => $date,
                'cashback_laari' => $cashback[$date]['cashback_laari'] ?? 0,
                'fee_accrued_laari' => $cashback[$date]['fee_laari'] ?? 0,
                'collected_laari' => $collected[$date] ?? 0,
                'paid_out_laari' => $paidOut[$date] ?? 0,
            ];
        }

        return $series;
    }
}
