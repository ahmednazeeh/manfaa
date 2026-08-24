<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Cashback\TransactionState;
use App\Domain\Settlement\SettlementState;
use App\Models\Settlement;
use App\Models\SettlementLine;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REPORT A — cashback, by the date the SALE happened.
 *
 * The period is measured on `transactions.occurred_at` in business time,
 * because that is the question finance actually asks: what did the shops
 * sell in August, and what did that cost us. Not when we heard about it,
 * not when it was settled — when it happened. A sale at 02:00 on 1 August
 * in Malé is 21:00 on 31 July in UTC, and it belongs to August; ReportPeriod
 * is what makes that true and CashbackReportTest is what keeps it true.
 *
 * Three sheets, Summary first in the workbook:
 *
 *   Summary       counts and totals by state, then the grand total, then the
 *                 same for the settlements the period submitted.
 *   Transactions  one row per sale, with the exact laari each one collected
 *                 (see SettlementLineAllocation for why that is not simply
 *                 cashback + fee + GST).
 *   Settlements   one row per batch submitted in the period.
 */
final class CashbackReport extends BaseReport
{
    public const string TRANSACTIONS = 'Transactions';

    public const string SETTLEMENTS = 'Settlements';

    public const string SUMMARY = 'Summary';

    public function key(): string
    {
        return 'cashback';
    }

    public function primarySheetTitle(): string
    {
        return self::TRANSACTIONS;
    }

    protected function countRows(): int
    {
        return (int) $this->transactionScope()->count();
    }

    /**
     * @return list<Sheet>
     */
    protected function build(): array
    {
        $transactions = $this->transactionsSheet();
        $settlements = $this->settlementsSheet();

        return [$this->summarySheet($transactions, $settlements), $transactions, $settlements];
    }

    /**
     * The driving query's WHERE, shared by the count and the build so the
     * cap can never be checked against a different set of rows than the one
     * that gets built.
     */
    private function transactionScope(): Builder
    {
        return DB::table('transactions')
            ->where('transactions.occurred_at', '>=', $this->period->start)
            ->where('transactions.occurred_at', '<', $this->period->end)
            ->when($this->merchantId !== null, fn ($query) => $query->where('transactions.merchant_id', $this->merchantId));
    }

    private function transactionsSheet(): Sheet
    {
        $sheet = new Sheet(
            self::TRANSACTIONS,
            [
                ReportColumn::date('occurred_at', 'Date'),
                ReportColumn::text('merchant', 'Merchant'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('invoice_no', 'Invoice no'),
                ReportColumn::text('customer_code', 'Customer code'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('origin', 'Origin'),
                ReportColumn::money('eligible_laari', 'Eligible sale'),
                ReportColumn::percent('rate_bp', 'Rate'),
                ReportColumn::money('cashback_laari', 'Cashback'),
                ReportColumn::money('fee_laari', 'Fee'),
                ReportColumn::money('gst_laari', 'GST'),
                ReportColumn::money('gross_due_laari', 'Gross due'),
                ReportColumn::money('discount_laari', 'Discount'),
                ReportColumn::money('forgiveness_laari', 'Forgiveness'),
                ReportColumn::money('collected_laari', 'Collected'),
                ReportColumn::text('state', 'State'),
                ReportColumn::text('settlement_ref', 'Settlement ref'),
                ReportColumn::text('funding_method', 'Funding'),
                ReportColumn::date('settled_at', 'Settled at'),
                ReportColumn::date('paid_at', 'Paid at'),
                ReportColumn::text('payout_batch_ref', 'Payout batch'),
            ],
            totals: [
                'eligible_laari', 'cashback_laari', 'fee_laari', 'gst_laari',
                'gross_due_laari', 'discount_laari', 'forgiveness_laari', 'collected_laari',
            ],
        );

        $allocations = $this->allocations();

        $rows = $this->transactionScope()
            ->leftJoin('merchants', 'merchants.id', '=', 'transactions.merchant_id')
            ->leftJoin('merchant_branches', 'merchant_branches.id', '=', 'transactions.branch_id')
            ->leftJoin('customers', 'customers.id', '=', 'transactions.customer_id')
            ->leftJoin('settlement_lines', 'settlement_lines.transaction_id', '=', 'transactions.id')
            ->leftJoin('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->leftJoin('payout_items', 'payout_items.id', '=', 'transactions.payout_item_id')
            ->leftJoin('payout_batches', 'payout_batches.id', '=', 'payout_items.batch_id')
            ->select([
                'transactions.id',
                'transactions.occurred_at',
                'transactions.invoice_no',
                'transactions.origin',
                'transactions.eligible_laari',
                'transactions.rate_bp',
                'transactions.cashback_laari',
                'transactions.fee_laari',
                'transactions.fee_gst_laari',
                'transactions.state',
                'merchants.name as merchant_name',
                'merchant_branches.name as branch_name',
                'customers.customer_code',
                'customers.name as customer_name',
                'settlements.id as settlement_id',
                'settlements.reference as settlement_ref',
                'settlements.funding_method',
                'settlement_lines.allocated_at',
                'payout_batches.reference as payout_batch_ref',
            ])
            // The paid instant lives in the append-only event log: nothing
            // stamps a paid_at column, and ItemResultService writes exactly
            // one such event per transaction when the bank confirms.
            ->selectRaw(
                '(select max(created_at) from transaction_events '
                .'where transaction_events.transaction_id = transactions.id and transaction_events.to_state = ?) as paid_at',
                [TransactionState::Paid->value],
            )
            ->orderBy('transactions.occurred_at')
            ->orderBy('transactions.id')
            ->lazy(2000);

        foreach ($rows as $row) {
            $transactionId = (int) $row->id;
            $settlementId = $row->settlement_id === null ? null : (int) $row->settlement_id;
            $allocation = $settlementId === null ? null : ($allocations[$settlementId] ?? null);

            $sheet->push([
                $this->at($row->occurred_at),
                (string) ($row->merchant_name ?? ''),
                (string) ($row->branch_name ?? ''),
                (string) ($row->invoice_no ?? ''),
                (string) ($row->customer_code ?? ''),
                $this->maskedName($row->customer_name),
                ReportLabels::origin($row->origin),
                (int) $row->eligible_laari,
                (int) $row->rate_bp,
                (int) $row->cashback_laari,
                (int) $row->fee_laari,
                (int) $row->fee_gst_laari,
                (int) $row->cashback_laari + (int) $row->fee_laari + (int) $row->fee_gst_laari,
                $allocation?->discountFor($transactionId) ?? 0,
                $allocation?->forgivenFor($transactionId) ?? 0,
                $allocation?->collectedFor($transactionId),
                ReportLabels::transactionState($row->state),
                (string) ($row->settlement_ref ?? ''),
                ReportLabels::fundingMethod($row->funding_method),
                $this->at($row->allocated_at),
                $this->at($row->paid_at),
                (string) ($row->payout_batch_ref ?? ''),
            ]);
        }

        return $sheet;
    }

    /**
     * Every settlement touched by a transaction in the period, with its
     * whole line set — the allocation walk is a property of the BATCH, so a
     * period that clips a batch in half still reads each line's collection
     * from the complete batch it belongs to.
     *
     * @return array<int, SettlementLineAllocation>
     */
    private function allocations(): array
    {
        $settlementIds = $this->transactionScope()
            ->join('settlement_lines', 'settlement_lines.transaction_id', '=', 'transactions.id')
            ->distinct()
            ->pluck('settlement_lines.settlement_id')
            ->map(intval(...))
            ->all();

        if ($settlementIds === []) {
            return [];
        }

        $forgiven = SettlementLineAllocation::forgivenBySettlement($settlementIds);

        $lines = SettlementLine::query()
            ->whereIn('settlement_lines.settlement_id', $settlementIds)
            ->join('transactions', 'transactions.id', '=', 'settlement_lines.transaction_id')
            ->orderBy('settlement_lines.settlement_id')
            // The §7 allocation order, exactly as SettlementLines states it.
            ->orderBy('transactions.due_at')
            ->orderBy('transactions.id')
            ->select('settlement_lines.*')
            ->get()
            ->groupBy('settlement_id');

        $allocations = [];

        foreach (Settlement::query()->whereIn('id', $settlementIds)->get() as $settlement) {
            $allocations[(int) $settlement->id] = SettlementLineAllocation::forLines(
                $settlement,
                array_values(($lines[$settlement->id] ?? collect())->all()),
                $forgiven[(int) $settlement->id] ?? 0,
            );
        }

        return $allocations;
    }

    private function settlementsSheet(): Sheet
    {
        $sheet = new Sheet(
            self::SETTLEMENTS,
            [
                ReportColumn::text('reference', 'Reference'),
                ReportColumn::text('merchant', 'Merchant'),
                ReportColumn::text('state', 'State'),
                ReportColumn::text('funding_method', 'Funding'),
                ReportColumn::int('line_count', 'Lines'),
                ReportColumn::money('cashback_total_laari', 'Cashback'),
                ReportColumn::money('fee_total_laari', 'Fee'),
                ReportColumn::money('gst_total_laari', 'GST'),
                ReportColumn::money('amount_due_laari', 'Amount due'),
                ReportColumn::percent('discount_rate_bp', 'Discount rate'),
                ReportColumn::money('discount_laari', 'Discount'),
                ReportColumn::money('forgiveness_laari', 'Forgiveness'),
                ReportColumn::money('amount_received_laari', 'Received'),
                ReportColumn::date('submitted_at', 'Submitted at'),
                ReportColumn::date('settled_at', 'Settled at'),
            ],
            totals: [
                'line_count', 'cashback_total_laari', 'fee_total_laari', 'gst_total_laari',
                'amount_due_laari', 'discount_laari', 'forgiveness_laari', 'amount_received_laari',
            ],
        );

        $rows = $this->settlementScope()
            ->leftJoin('merchants', 'merchants.id', '=', 'settlements.merchant_id')
            ->select([
                'settlements.id',
                'settlements.reference',
                'settlements.state',
                'settlements.funding_method',
                'settlements.cashback_total_laari',
                'settlements.fee_total_laari',
                'settlements.fee_gst_total_laari',
                'settlements.amount_due_laari',
                'settlements.discount_rate_bp',
                'settlements.discount_laari',
                'settlements.amount_received_laari',
                'settlements.created_at',
                'merchants.name as merchant_name',
            ])
            // Lines and their allocation instants, counted where they live.
            // `settled_at` is the last line to be covered: settlements carry
            // no settled_at column, and the allocation stamp is the fact.
            ->selectRaw('(select count(*) from settlement_lines where settlement_lines.settlement_id = settlements.id) as line_count')
            ->selectRaw('(select max(allocated_at) from settlement_lines where settlement_lines.settlement_id = settlements.id) as settled_at')
            ->orderBy('settlements.created_at')
            ->orderBy('settlements.id')
            ->lazy(2000);

        $forgiven = SettlementLineAllocation::forgivenBySettlement(
            $this->settlementScope()->pluck('settlements.id')->map(intval(...))->all(),
        );

        foreach ($rows as $row) {
            $sheet->push([
                (string) $row->reference,
                (string) ($row->merchant_name ?? ''),
                ReportLabels::settlementState($row->state),
                ReportLabels::fundingMethod($row->funding_method),
                (int) $row->line_count,
                (int) $row->cashback_total_laari,
                (int) $row->fee_total_laari,
                (int) $row->fee_gst_total_laari,
                (int) $row->amount_due_laari,
                $row->discount_rate_bp === null ? null : (int) $row->discount_rate_bp,
                (int) $row->discount_laari,
                $forgiven[(int) $row->id] ?? 0,
                (int) $row->amount_received_laari,
                $this->at($row->created_at),
                $this->at($row->settled_at),
            ]);
        }

        return $sheet;
    }

    /**
     * Settlements are periodised on SUBMISSION (`created_at`): receipt-first
     * means a settlement exists because a merchant transferred, so the row's
     * birthday is the day they paid.
     *
     * DRAFT and CANCELLED batches are left out, and the sheet is a money
     * sheet — every row's `amount_due_laari` is added into a `=SUM()` totals
     * row and into the summary block the panel's tiles read. Neither state
     * is money anybody owes:
     *
     *   - A DRAFT is a basket. `createDraft` writes the row and its lines
     *     the moment a merchant opens the settle page; nothing has been
     *     committed to, and an abandoned draft would otherwise report its
     *     whole line total as due.
     *   - A CANCELLED batch is money that was owed and is owed again, on a
     *     DIFFERENT row. `SettlementBuilder::reject` cancels the batch and
     *     releases its lines, the merchant re-submits, and the identical
     *     laari would then appear twice — once dead, once live — doubling
     *     the amount due for a merchant who simply attached the wrong slip.
     *
     * The transaction rows are unaffected: cancellation deletes the lines,
     * so nothing joins back to a cancelled batch, and a line still sitting
     * on a draft collects nothing and says so with a blank Collected cell.
     */
    private function settlementScope(): Builder
    {
        return DB::table('settlements')
            ->whereNotIn('settlements.state', [
                SettlementState::Draft->value,
                SettlementState::Cancelled->value,
            ])
            ->where('settlements.created_at', '>=', $this->period->start)
            ->where('settlements.created_at', '<', $this->period->end)
            ->when($this->merchantId !== null, fn ($query) => $query->where('settlements.merchant_id', $this->merchantId));
    }

    /**
     * Counts and totals by state, then the grand total, then the same for
     * settlements — one column set, every row labelled with what it counts.
     * Built from the two detail sheets rather than from its own queries, so
     * the Summary can never disagree with the rows underneath it.
     */
    private function summarySheet(Sheet $transactions, Sheet $settlements): Sheet
    {
        $sheet = new Sheet(self::SUMMARY, [
            ReportColumn::text('metric', 'Metric'),
            ReportColumn::int('count', 'Count'),
            ReportColumn::money('eligible_laari', 'Eligible sale'),
            ReportColumn::money('cashback_laari', 'Cashback'),
            ReportColumn::money('fee_laari', 'Fee'),
            ReportColumn::money('gst_laari', 'GST'),
            ReportColumn::money('gross_due_laari', 'Gross due'),
            ReportColumn::money('collected_laari', 'Collected'),
        ]);

        $stateIndex = $transactions->indexOf('state');
        $buckets = [];

        foreach (TransactionState::cases() as $state) {
            $buckets[$state->label()] = ['count' => 0, 'eligible' => 0, 'cashback' => 0, 'fee' => 0, 'gst' => 0, 'gross' => 0, 'collected' => 0];
        }

        $columns = [
            'eligible' => $transactions->indexOf('eligible_laari'),
            'cashback' => $transactions->indexOf('cashback_laari'),
            'fee' => $transactions->indexOf('fee_laari'),
            'gst' => $transactions->indexOf('gst_laari'),
            'gross' => $transactions->indexOf('gross_due_laari'),
            'collected' => $transactions->indexOf('collected_laari'),
        ];

        foreach ($transactions->rows() as $row) {
            $label = (string) $row[$stateIndex];
            $buckets[$label] ??= ['count' => 0, 'eligible' => 0, 'cashback' => 0, 'fee' => 0, 'gst' => 0, 'gross' => 0, 'collected' => 0];
            $buckets[$label]['count']++;

            foreach ($columns as $name => $index) {
                $buckets[$label][$name] += (int) ($row[$index] ?? 0);
            }
        }

        foreach ($buckets as $label => $bucket) {
            $sheet->push([
                'Transactions — '.$label,
                $bucket['count'],
                $bucket['eligible'],
                $bucket['cashback'],
                $bucket['fee'],
                $bucket['gst'],
                $bucket['gross'],
                $bucket['collected'],
            ]);
        }

        $sheet->push([
            'Transactions — all states',
            $transactions->count(),
            $transactions->sum('eligible_laari'),
            $transactions->sum('cashback_laari'),
            $transactions->sum('fee_laari'),
            $transactions->sum('gst_laari'),
            $transactions->sum('gross_due_laari'),
            $transactions->sum('collected_laari'),
        ]);

        $settlementStates = [];
        $settlementStateIndex = $settlements->indexOf('state');

        foreach (SettlementState::cases() as $state) {
            $settlementStates[$state->label()] = ['count' => 0, 'cashback' => 0, 'fee' => 0, 'gst' => 0, 'due' => 0, 'received' => 0];
        }

        foreach ($settlements->rows() as $row) {
            $label = (string) $row[$settlementStateIndex];
            $settlementStates[$label] ??= ['count' => 0, 'cashback' => 0, 'fee' => 0, 'gst' => 0, 'due' => 0, 'received' => 0];
            $settlementStates[$label]['count']++;
            $settlementStates[$label]['cashback'] += (int) $row[$settlements->indexOf('cashback_total_laari')];
            $settlementStates[$label]['fee'] += (int) $row[$settlements->indexOf('fee_total_laari')];
            $settlementStates[$label]['gst'] += (int) $row[$settlements->indexOf('gst_total_laari')];
            $settlementStates[$label]['due'] += (int) $row[$settlements->indexOf('amount_due_laari')];
            $settlementStates[$label]['received'] += (int) $row[$settlements->indexOf('amount_received_laari')];
        }

        foreach ($settlementStates as $label => $bucket) {
            $sheet->push([
                'Settlements — '.$label,
                $bucket['count'],
                null,
                $bucket['cashback'],
                $bucket['fee'],
                $bucket['gst'],
                $bucket['due'],
                $bucket['received'],
            ]);
        }

        $sheet->push([
            'Settlements — all states',
            $settlements->count(),
            null,
            $settlements->sum('cashback_total_laari'),
            $settlements->sum('fee_total_laari'),
            $settlements->sum('gst_total_laari'),
            $settlements->sum('amount_due_laari'),
            $settlements->sum('amount_received_laari'),
        ]);

        return $sheet;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $transactions = $this->sheet(self::TRANSACTIONS);
        $settlements = $this->sheet(self::SETTLEMENTS);
        $summary = $this->sheet(self::SUMMARY);

        $byState = [];
        $settlementsByState = [];

        foreach ($summary->rows() as $row) {
            $label = (string) $row[0];

            if ((int) $row[1] === 0) {
                continue;
            }

            if (str_starts_with($label, 'Transactions — ') && $label !== 'Transactions — all states') {
                $byState[] = [
                    'state' => substr($label, strlen('Transactions — ')),
                    'count' => (int) $row[1],
                    'eligible_laari' => (int) $row[2],
                    'cashback_laari' => (int) $row[3],
                    'fee_laari' => (int) $row[4],
                    'gst_laari' => (int) $row[5],
                    'gross_due_laari' => (int) $row[6],
                    'collected_laari' => (int) $row[7],
                ];

                continue;
            }

            // The same breakdown for the batches. `settled` and
            // `partially_settled` are very different sentences about the
            // same amount due, and a caller reading only the all-states
            // total cannot tell them apart.
            if (str_starts_with($label, 'Settlements — ') && $label !== 'Settlements — all states') {
                $settlementsByState[] = [
                    'state' => substr($label, strlen('Settlements — ')),
                    'count' => (int) $row[1],
                    'cashback_total_laari' => (int) $row[3],
                    'fee_total_laari' => (int) $row[4],
                    'gst_total_laari' => (int) $row[5],
                    'amount_due_laari' => (int) $row[6],
                    'amount_received_laari' => (int) $row[7],
                ];
            }
        }

        return [
            'transactions' => [
                'count' => $transactions->count(),
                'eligible_laari' => $transactions->sum('eligible_laari'),
                'cashback_laari' => $transactions->sum('cashback_laari'),
                'fee_laari' => $transactions->sum('fee_laari'),
                'gst_laari' => $transactions->sum('gst_laari'),
                'gross_due_laari' => $transactions->sum('gross_due_laari'),
                'discount_laari' => $transactions->sum('discount_laari'),
                'forgiveness_laari' => $transactions->sum('forgiveness_laari'),
                'collected_laari' => $transactions->sum('collected_laari'),
            ],
            'by_state' => $byState,
            'settlements' => [
                'count' => $settlements->count(),
                'line_count' => $settlements->sum('line_count'),
                'cashback_total_laari' => $settlements->sum('cashback_total_laari'),
                'fee_total_laari' => $settlements->sum('fee_total_laari'),
                'gst_total_laari' => $settlements->sum('gst_total_laari'),
                'amount_due_laari' => $settlements->sum('amount_due_laari'),
                'discount_laari' => $settlements->sum('discount_laari'),
                'forgiveness_laari' => $settlements->sum('forgiveness_laari'),
                'amount_received_laari' => $settlements->sum('amount_received_laari'),
                'by_state' => $settlementsByState,
            ],
        ];
    }
}
