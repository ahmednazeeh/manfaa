<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\EarningsReport;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportPeriod;

/**
 * THE MONEY PANEL — and the one rule that governs it: the dashboard must
 * never disagree with the Reports page.
 *
 * So it does not define anything. Every figure below is read from the report
 * class that owns it, through a method that skips the sheet-building and
 * runs the same scope as one aggregate:
 *
 *   cashback_generated        CashbackReport::moneyTotals() — sales by
 *                             occurred_at in business time, reversed sales
 *                             excluded, exactly as REPORT A counts them
 *   platform_fees_net         EarningsReport::ledgerSummary() — fee revenue
 *                             (4100) less prompt discounts less forgiven
 *                             shortfalls, from the LEDGER by posted_at,
 *                             which is REPORT C's whole design
 *   gst_collected             the same ledger pass, account 2300, kept
 *                             SEPARATE because it is a liability owed to
 *                             MIRA and not income (zero everywhere today)
 *   fee_forgone_to_promotions CashbackReport::moneyTotals() — the §4 tier
 *                             fee those sales would have paid, less what
 *                             they did pay, summed from the column frozen
 *                             on each row. The acquisition spend the owner
 *                             asked to be able to see (2026-08-25)
 *   collected_from_merchants  the Settlements sheet's own amount_received
 *                             total over the batches the period raised
 *   paid_out_to_customers     PayoutReport::paidTotals() — cashback whose
 *                             PAID event landed in the period
 *
 * TWO CLOCKS, DELIBERATELY. Cashback is dated by the SALE and fees by the
 * JOURNAL, because that is what the two reports do — a fee accrues with the
 * sale and is recognised where the ledger posts it. Reproducing that
 * faithfully is the point: a dashboard that quietly re-dated fees onto
 * occurred_at would look tidier and would disagree with the earnings report
 * every month. The field names carry their own basis, and
 * DashboardReportsAgreementTest asserts each figure equals its report's.
 *
 * `fee_forgone_to_promotions_laari` is on the SALE clock, with cashback, and
 * not on the journal clock with the fees — because it is not a ledger
 * movement at all. A discount we never charged posts nothing: 4100 is
 * credited with the fee we DID charge, and there is no journal for the
 * difference. It is a memo figure carried on each sale, so it is counted
 * where that sale is counted, which is REPORT A. The field name says
 * `forgone`, not `income`, for the same reason.
 *
 * SUPERADMIN ONLY, matching the Reports gate: these five numbers cross every
 * merchant and every customer at once.
 */
final class MoneySnapshot
{
    /**
     * @return array{cashback_generated_laari: int, platform_fees_net_laari: int, gst_collected_laari: int, fee_forgone_to_promotions_laari: int, collected_from_merchants_laari: int, paid_out_to_customers_laari: int}
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $cashback = (new CashbackReport($period))->moneyTotals();
        $ledger = (new EarningsReport($period))->ledgerSummary();
        $payouts = (new PayoutReport($period))->paidTotals();

        return [
            'cashback_generated_laari' => $cashback['transactions']['cashback_laari'],
            'platform_fees_net_laari' => $ledger['net_fee_income_laari'],
            'gst_collected_laari' => $ledger['gst_collected_laari'],
            'fee_forgone_to_promotions_laari' => $cashback['transactions']['fee_forgone_laari'],
            'collected_from_merchants_laari' => $cashback['settlements']['amount_received_laari'],
            'paid_out_to_customers_laari' => $payouts['cashback_laari'],
        ];
    }
}
