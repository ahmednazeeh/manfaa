<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Cashback\TransactionState;
use App\Domain\Payout\PayoutItemState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REPORT B — money leaving the platform, by the date it was PAID.
 *
 * The paid instant comes from the append-only event log: nothing stamps a
 * `paid_at` column on a transaction, and ItemResultService writes exactly
 * one `to_state = paid` event per transaction when the bank's outcome is
 * recorded. That event is therefore the fact, and every sheet here is
 * periodised on it.
 *
 * THE TIE. Three sheets count the same money three ways, and the report is
 * only worth reading if they agree:
 *
 *     Σ Transactions.Cashback  ==  Σ Payouts."Paid in period"  ==  Σ Batches."Paid in period"
 *
 * "Paid in period" is deliberately NOT the payout item's own amount. An item
 * carries what the bank was told to send; the period wants what was actually
 * paid inside it — so the column sums the item's transactions that this
 * report contains. Unfiltered, for a paid item, the two are the same number;
 * for a FAILED item, "Paid in period" is zero, which is exactly why the tie
 * survives a batch with a failure in it. Under a merchant filter the column
 * narrows to that merchant's share while the item's own amount stays whole,
 * so the tie holds there too and nothing is misreported as smaller than it
 * was.
 *
 * Failed items still appear (with their reason) when no merchant filter is
 * set: a failure has no transactions left linked — ItemResultService unlinks
 * them so they re-enter the next batch — so it cannot be attributed to a
 * merchant, and a filtered report would be inventing an attribution.
 *
 * REVERSED ROWS. `include_reversed` is accepted here and plumbed into the
 * driving scope, but it cannot change a figure and that is by construction:
 * every row on this report has a `paid` event, `paid` is terminal in §6,
 * and reversal is refused from `confirmed` onward anyway. The predicate is
 * carried regardless so the promise "no report shows reversed rows unless
 * asked" is enforced by the query rather than by a paragraph — and if the
 * state machine is ever loosened, the Transactions sheet and the per-item
 * attribution narrow TOGETHER (both read paidScope()), so the three-way tie
 * survives the change instead of quietly breaking.
 */
final class PayoutReport extends BaseReport
{
    public const string TRANSACTIONS = 'Transactions';

    /**
     * Titles carry the direction (owner, 2026-08-24). "Payouts" beside
     * "Settlements" in another workbook is exactly the pair a tax
     * professional cannot tell apart, and the tab is the first thing they
     * read. All within Excel's 31-character limit.
     */
    public const string PAYOUTS = 'Payouts (money out)';

    public const string BATCHES = 'Payout batches (money out)';

    public const string WITHDRAWALS = 'Wallet withdrawals (money out)';

    public const string SUMMARY = 'Summary';

    public function key(): string
    {
        return 'payouts';
    }

    /**
     * The header block's "Reversed rows" line, in BOTH flag states, because
     * this report holds the same rows in both.
     *
     * The inherited sentence would tell the reader that reversed sales
     * "appear below, with 'reversed' in their State column" — on a sheet
     * with no State column, three rows above this report's own note saying
     * a reversed sale cannot reach it. Saying the same true thing whichever
     * way the switch was left is the only version a reader can trust.
     */
    protected function reversedRowsNotApplicable(): ?string
    {
        return 'Not applicable — every row on this report was PAID, and §6 makes paid terminal, so a '
            .'reversed sale can never appear here. The setting changes nothing on this report.';
    }

    public function primarySheetTitle(): string
    {
        return self::TRANSACTIONS;
    }

    protected function countRows(): int
    {
        return (int) $this->paidScope()->count();
    }

    /**
     * @return list<Sheet>
     */
    protected function build(): array
    {
        $transactions = $this->transactionsSheet();
        // The batch aggregation is handed over by the sheet that built the
        // rows it aggregates, keyed by batch ID — never by reference. A
        // cancelled batch KEEPS its reference (the unique index spares only
        // the live ones), so production carries three PB-20260816 rows, and
        // keying on the string counted the same money once per duplicate.
        $paidByBatch = [];
        $payouts = $this->payoutsSheet($paidByBatch);
        $batches = $this->batchesSheet($paidByBatch);
        $withdrawals = $this->withdrawalsSheet();

        return [
            $this->summarySheet($transactions, $payouts, $batches, $withdrawals),
            $transactions,
            $payouts,
            $batches,
            $withdrawals,
        ];
    }

    /**
     * Transactions whose PAID event landed in the period. The join is to a
     * grouped derived table rather than a correlated subquery so the same
     * definition drives the count, the sheet and the per-item attribution.
     *
     * THE LOWER BOUND IS PUSHED INTO THE GROUPING, THE UPPER BOUND IS NOT,
     * and the asymmetry is the whole point.
     *
     * `max(created_at) >= start` filters the aggregate, so without a
     * predicate inside the subquery Postgres has to group EVERY paid event
     * ever recorded and throw away all but the period's — cost O(lifetime),
     * not O(period), on a page that is now the admin landing on a 60s poll.
     * Pushing `created_at >= start` down is provably equivalent: if the true
     * max is >= start the filter keeps every row that could BE the max, and
     * if the true max is < start the group was going to be discarded anyway.
     *
     * The upper bound must NOT be pushed. Dropping events >= end would let an
     * EARLIER paid event become the max and pull a transaction that was
     * re-paid after the window back into it — the one thing the outer filter
     * is here to prevent. (`transaction_events (to_state, created_at)`,
     * migration 2026_08_25_120000, is the index this reads through.)
     */
    private function paidScope(): Builder
    {
        $paid = DB::table('transaction_events')
            ->where('to_state', TransactionState::Paid->value)
            ->where('created_at', '>=', $this->period->start)
            ->groupBy('transaction_id')
            ->select('transaction_id', DB::raw('max(created_at) as paid_at'));

        return DB::table('transactions')
            ->joinSub($paid, 'paid_event', 'paid_event.transaction_id', '=', 'transactions.id')
            ->where('paid_event.paid_at', '>=', $this->period->start)
            ->where('paid_event.paid_at', '<', $this->period->end)
            // A no-op today (see the class docblock) and deliberately kept:
            // the rule is enforced where the rows are chosen, not assumed.
            ->when(
                ! $this->options->includeReversed,
                fn ($query) => $query->where('transactions.state', '!=', TransactionState::Reversed->value),
            )
            ->when($this->merchantId !== null, fn ($query) => $query->where('transactions.merchant_id', $this->merchantId));
    }

    private function transactionsSheet(): Sheet
    {
        $sheet = new Sheet(
            self::TRANSACTIONS,
            [
                ReportColumn::date('paid_at', 'Paid at'),
                ReportColumn::text('batch_ref', 'Payout batch ref'),
                ReportColumn::text('customer_code', 'Customer code'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('merchant', 'Merchant'),
                ReportColumn::text('invoice_no', 'Invoice no'),
                ReportColumn::date('occurred_at', 'Occurred at'),
                ReportColumn::money('cashback_laari', 'Cashback'),
                // The bank's own reference for the transfer that paid this
                // sale (owner, 2026-08-24). It lives on the payout ITEM —
                // one transfer pays a customer's whole item, which may
                // cover several sales — so the same reference repeats down
                // the sheet, which is exactly what a reader matching a bank
                // statement line to the sales behind it needs.
                ReportColumn::text('bank_reference', 'Transfer reference'),
            ],
            totals: ['cashback_laari'],
        );

        $rows = $this->paidScope()
            ->leftJoin('customers', 'customers.id', '=', 'transactions.customer_id')
            ->leftJoin('merchants', 'merchants.id', '=', 'transactions.merchant_id')
            ->leftJoin('payout_items', 'payout_items.id', '=', 'transactions.payout_item_id')
            ->leftJoin('payout_batches', 'payout_batches.id', '=', 'payout_items.batch_id')
            ->select([
                'transactions.id',
                'transactions.invoice_no',
                'transactions.occurred_at',
                'transactions.cashback_laari',
                'paid_event.paid_at',
                'customers.customer_code',
                'customers.name as customer_name',
                'merchants.name as merchant_name',
                'payout_batches.reference as batch_ref',
                'payout_items.bank_reference',
            ])
            ->orderBy('paid_event.paid_at')
            ->orderBy('transactions.id')
            ->lazy(2000);

        foreach ($rows as $row) {
            $sheet->push([
                $this->at($row->paid_at),
                (string) ($row->batch_ref ?? ''),
                (string) ($row->customer_code ?? ''),
                $this->personName($row->customer_name),
                (string) ($row->merchant_name ?? ''),
                (string) ($row->invoice_no ?? ''),
                $this->at($row->occurred_at),
                (int) $row->cashback_laari,
                (string) ($row->bank_reference ?? ''),
            ]);
        }

        return $sheet;
    }

    /**
     * Cashback paid inside the period, per payout item — the attribution the
     * tie rests on.
     *
     * @return array<int, array{laari: int, count: int, paid_at: string|null}>
     */
    private function paidByItem(): array
    {
        $rows = $this->paidScope()
            ->whereNotNull('transactions.payout_item_id')
            ->groupBy('transactions.payout_item_id')
            ->select('transactions.payout_item_id')
            ->selectRaw('SUM(transactions.cashback_laari) AS laari, COUNT(*) AS transactions, MAX(paid_event.paid_at) AS paid_at')
            ->get();

        $byItem = [];

        foreach ($rows as $row) {
            $byItem[(int) $row->payout_item_id] = [
                'laari' => (int) $row->laari,
                'count' => (int) $row->transactions,
                'paid_at' => $row->paid_at,
            ];
        }

        return $byItem;
    }

    /**
     * @param  array<int, array{paid: int, outcome: CarbonImmutable|null}>  $paidByBatch
     *                                                                                    filled as the rows are written: batch id => what it paid inside
     *                                                                                    the period, and when its last outcome landed
     */
    private function payoutsSheet(array &$paidByBatch): Sheet
    {
        $sheet = new Sheet(
            self::PAYOUTS,
            [
                // Printed ONCE per batch in the workbook (see the grouping
                // below), so a batch reads as one block instead of forty
                // copies of the same string down a column.
                ReportColumn::text('batch_ref', 'Payout batch ref'),
                // The machine key that survives that blanking — an ordinary
                // VISIBLE column, present on every row, so filtering or
                // sorting by batch still catches all of it. An autofilter
                // reads the cells that are there; a hidden column would be
                // invisible to the reader who needs it most.
                ReportColumn::int('batch_id', 'Batch key'),
                ReportColumn::text('customer_code', 'Customer code'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('bank', 'Bank'),
                ReportColumn::text('account', 'Account'),
                // The name the money was sent TO, as the bank saw it — the
                // other half of an account number, and the thing a
                // reconciliation actually reads when a transfer bounces.
                ReportColumn::text('account_name', 'Account name'),
                ReportColumn::money('amount_laari', 'Payout amount'),
                ReportColumn::money('paid_laari', 'Paid out in period'),
                ReportColumn::int('transaction_count', 'Transactions'),
                ReportColumn::text('status', 'Status'),
                // payout_items.bank_reference — the bank's own reference for
                // the transfer, populated on every live paid item.
                ReportColumn::text('bank_reference', 'Transfer reference'),
                ReportColumn::date('outcome_at', 'Paid / failed at'),
                ReportColumn::text('failure_reason', 'Failure reason'),
            ],
            totals: ['amount_laari', 'paid_laari', 'transaction_count'],
            // Workbook presentation only — the rows below stay fully
            // populated, and so does the JSON preview.
            grouping: new SheetGrouping(keyColumn: 'batch_id', labelColumn: 'batch_ref'),
        );

        $paidByItem = $this->paidByItem();
        $itemIds = array_keys($paidByItem);

        // Failed items carry no transactions — they were unlinked so they
        // could re-enter the next batch — so they are found by their own
        // outcome stamp, and only when nothing is being attributed to one
        // merchant. `updated_at` is that stamp: payout_items has no
        // failed_at column, and applyFailed is the only thing that writes
        // the row into that state.
        $failedIds = $this->merchantId !== null ? [] : DB::table('payout_items')
            ->where('state', PayoutItemState::Failed->value)
            ->where('updated_at', '>=', $this->period->start)
            ->where('updated_at', '<', $this->period->end)
            ->pluck('id')
            ->map(intval(...))
            ->all();

        $ids = array_values(array_unique([...$itemIds, ...$failedIds]));

        if ($ids === []) {
            return $sheet;
        }

        $rows = DB::table('payout_items')
            ->whereIn('payout_items.id', $ids)
            ->leftJoin('customers', 'customers.id', '=', 'payout_items.customer_id')
            ->leftJoin('payout_batches', 'payout_batches.id', '=', 'payout_items.batch_id')
            ->select([
                'payout_items.id',
                'payout_items.batch_id',
                'payout_items.amount_laari',
                'payout_items.bank',
                'payout_items.account',
                'payout_items.state',
                'payout_items.failure_reason',
                'payout_items.customer_name',
                'payout_items.account_name',
                'payout_items.bank_reference',
                'payout_items.updated_at',
                'customers.customer_code',
                'payout_batches.reference as batch_ref',
            ])
            // BY BATCH, THEN BY CUSTOMER WITHIN IT (owner, 2026-08-24) — the
            // order the grouping needs, and the order a person reads a
            // payout run in.
            //
            // batch_id is a tiebreak on the reference rather than
            // decoration: a cancelled batch KEEPS its reference (the unique
            // index spares only the live ones), so production holds three
            // PB-20260816 rows. Ordering on the reference alone would
            // interleave two genuinely different batches, and the blocks
            // would describe money that never moved together.
            ->orderBy('payout_batches.reference')
            ->orderBy('payout_items.batch_id')
            ->orderBy('customers.customer_code')
            ->orderBy('payout_items.customer_name')
            ->orderBy('payout_items.id')
            ->lazy(2000);

        foreach ($rows as $row) {
            $attributed = $paidByItem[(int) $row->id] ?? null;
            $batchId = (int) $row->batch_id;
            $outcomeAt = $this->at($attributed['paid_at'] ?? $row->updated_at);

            $paidByBatch[$batchId] ??= ['paid' => 0, 'outcome' => null];
            $paidByBatch[$batchId]['paid'] += $attributed['laari'] ?? 0;

            if ($outcomeAt !== null && ($paidByBatch[$batchId]['outcome'] === null
                || $outcomeAt->greaterThan($paidByBatch[$batchId]['outcome']))) {
                $paidByBatch[$batchId]['outcome'] = $outcomeAt;
            }

            $sheet->push([
                (string) ($row->batch_ref ?? ''),
                $batchId,
                (string) ($row->customer_code ?? ''),
                $this->personName($row->customer_name),
                (string) ($row->bank ?? ''),
                $this->bankAccount($row->account),
                $this->personName($row->account_name),
                (int) $row->amount_laari,
                $attributed['laari'] ?? 0,
                $attributed['count'] ?? 0,
                ReportLabels::payoutItemState($row->state),
                (string) ($row->bank_reference ?? ''),
                $outcomeAt,
                (string) ($row->failure_reason ?? ''),
            ]);
        }

        return $sheet;
    }

    /**
     * @param  array<int, array{paid: int, outcome: CarbonImmutable|null}>  $paidByBatch
     */
    private function batchesSheet(array $paidByBatch): Sheet
    {
        $sheet = new Sheet(
            self::BATCHES,
            [
                // `payout_batches` carries no bank reference of its own —
                // the bank references a TRANSFER, and a batch is many
                // transfers, one per customer, each with its own
                // `payout_items.bank_reference` on the Payouts sheet. What
                // the batch does carry is the two instants that say how it
                // reached the bank at all, and those are what a
                // reconciliation chases when a whole batch is unaccounted
                // for: the transfer sheet a human uploaded, or the API call.
                ReportColumn::text('reference', 'Payout batch ref'),
                // The join key the Payouts sheet carries on every row. That
                // sheet prints `batch_ref` once per block, so a reader who
                // filters it by anything else (merchant, bank, status) is
                // left holding this integer — and an integer that appears
                // in only one of two sheets joins to nothing.
                ReportColumn::int('batch_id', 'Batch key'),
                ReportColumn::text('period', 'Period covered'),
                ReportColumn::date('created_at', 'Created at'),
                ReportColumn::date('approved_at', 'Approved at'),
                ReportColumn::date('exported_at', 'Transfer sheet exported at'),
                ReportColumn::date('api_sent_at', 'Sent to bank at'),
                ReportColumn::date('paid_at', 'Last outcome at'),
                ReportColumn::int('item_count', 'Items'),
                ReportColumn::money('total_laari', 'Batch amount'),
                ReportColumn::money('paid_laari', 'Paid out in period'),
                ReportColumn::int('excluded_customer_count', 'Excluded customers'),
                ReportColumn::money('excluded_total_laari', 'Excluded total'),
                ReportColumn::text('status', 'Status'),
            ],
            totals: ['item_count', 'total_laari', 'paid_laari', 'excluded_customer_count', 'excluded_total_laari'],
        );

        if ($paidByBatch === []) {
            return $sheet;
        }

        $rows = DB::table('payout_batches')
            ->whereIn('id', array_keys($paidByBatch))
            ->orderBy('period_end')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $batch = $paidByBatch[(int) $row->id];

            $sheet->push([
                (string) $row->reference,
                (int) $row->id,
                sprintf('%s to %s', $row->period_start, $row->period_end),
                $this->at($row->created_at),
                $this->at($row->approved_at),
                $this->at($row->exported_at),
                $this->at($row->api_sent_at),
                $batch['outcome'],
                (int) $row->customer_count,
                (int) $row->total_laari,
                $batch['paid'],
                (int) $row->excluded_customer_count,
                (int) $row->excluded_total_laari,
                ReportLabels::payoutBatchState($row->state),
            ]);
        }

        return $sheet;
    }

    /**
     * Customer wallet withdrawals (`customer_payouts`) — the other road out
     * of the platform. Periodised on the instant the queue last acted on
     * them, falling back to the request when nothing has yet.
     *
     * A merchant filter empties this sheet on purpose: a wallet withdrawal
     * is a customer's balance leaving, with no merchant on it at all, and
     * showing every platform withdrawal under one shop's report would be a
     * lie about whose money it was.
     */
    private function withdrawalsSheet(): Sheet
    {
        $sheet = new Sheet(
            self::WITHDRAWALS,
            [
                ReportColumn::text('customer_code', 'Customer code'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::money('amount_laari', 'Amount paid out'),
                ReportColumn::text('bank', 'Bank'),
                ReportColumn::text('account', 'Account'),
                ReportColumn::text('account_name', 'Account name'),
                ReportColumn::text('status', 'Status'),
                // `customer_payouts.trx_id` is the bank's own id for this
                // withdrawal — the same fact `payout_items.bank_reference`
                // carries for a cashback payout, under the column label a
                // reader of both sheets will look for.
                ReportColumn::text('trx_id', 'Transfer reference'),
                ReportColumn::date('requested_at', 'Requested at'),
                ReportColumn::date('processed_at', 'Paid at'),
                ReportColumn::text('failure_reason', 'Failure reason'),
            ],
            totals: ['amount_laari'],
        );

        if ($this->merchantId !== null) {
            return $sheet;
        }

        $rows = DB::table('customer_payouts')
            ->leftJoin('customers', 'customers.id', '=', 'customer_payouts.customer_id')
            ->whereRaw('coalesce(customer_payouts.processed_at, customer_payouts.requested_at) >= ?', [$this->period->start])
            ->whereRaw('coalesce(customer_payouts.processed_at, customer_payouts.requested_at) < ?', [$this->period->end])
            ->select([
                'customer_payouts.amount_laari',
                'customer_payouts.bank',
                'customer_payouts.account',
                'customer_payouts.account_name',
                'customer_payouts.state',
                'customer_payouts.trx_id',
                'customer_payouts.requested_at',
                'customer_payouts.processed_at',
                'customer_payouts.failure_reason',
                'customers.customer_code',
                'customers.name as customer_name',
            ])
            ->orderBy('customer_payouts.requested_at')
            ->orderBy('customer_payouts.id')
            ->lazy(2000);

        foreach ($rows as $row) {
            $sheet->push([
                (string) ($row->customer_code ?? ''),
                $this->personName($row->customer_name),
                (int) $row->amount_laari,
                (string) ($row->bank ?? ''),
                $this->bankAccount($row->account),
                $this->personName($row->account_name),
                ReportLabels::walletPayoutState($row->state),
                (string) ($row->trx_id ?? ''),
                $this->at($row->requested_at),
                $this->at($row->processed_at),
                (string) ($row->failure_reason ?? ''),
            ]);
        }

        return $sheet;
    }

    private function summarySheet(Sheet $transactions, Sheet $payouts, Sheet $batches, Sheet $withdrawals): Sheet
    {
        $sheet = new Sheet(
            self::SUMMARY,
            [
                ReportColumn::text('metric', 'Metric'),
                ReportColumn::int('count', 'Count'),
                ReportColumn::money('amount_laari', 'Amount'),
            ],
            header: $this->headerFor('Manfaa — customer payout report', [
                'Every figure on this report is money OUT. Nothing here is owed to Manfaa and nothing '
                    .'here is income; the fee Manfaa earned on these same sales is on the earnings report.',
                'Reversed sales cannot appear on this report at all: every row was PAID, and §6 makes '
                    .'paid terminal — a sale cannot be reversed after it is confirmed, let alone paid. '
                    .'The reversed-rows setting therefore changes nothing here.',
            ]),
        );

        $statusIndex = $payouts->indexOf('status');
        $amountIndex = $payouts->indexOf('amount_laari');

        $failedCount = 0;
        $failedLaari = 0;

        foreach ($payouts->rows() as $row) {
            if ((string) $row[$statusIndex] === 'failed') {
                $failedCount++;
                $failedLaari += (int) $row[$amountIndex];
            }
        }

        $withdrawalStatus = $withdrawals->indexOf('status');
        $withdrawalAmount = $withdrawals->indexOf('amount_laari');

        $withdrawalsPaid = ['count' => 0, 'laari' => 0];
        $withdrawalsReturned = ['count' => 0, 'laari' => 0];

        foreach ($withdrawals->rows() as $row) {
            $status = (string) $row[$withdrawalStatus];

            if ($status === 'paid') {
                $withdrawalsPaid['count']++;
                $withdrawalsPaid['laari'] += (int) $row[$withdrawalAmount];
            } elseif ($status === 'failed' || $status === 'refunded to wallet') {
                $withdrawalsReturned['count']++;
                $withdrawalsReturned['laari'] += (int) $row[$withdrawalAmount];
            }
        }

        $cashbackPaid = $transactions->sum('cashback_laari');

        $sheet->push(['Cashback transactions paid', $transactions->count(), $cashbackPaid]);
        $sheet->push(['Payout items in period', $payouts->count(), $payouts->sum('amount_laari')]);
        $sheet->push(['Payout items — paid in period', $payouts->count() - $failedCount, $payouts->sum('paid_laari')]);
        $sheet->push(['Payout items — failed', $failedCount, $failedLaari]);
        $sheet->push(['Payout batches touched', $batches->count(), $batches->sum('paid_laari')]);
        $sheet->push(['Batch totals (every item)', $batches->count(), $batches->sum('total_laari')]);
        $sheet->push(['Excluded — awaiting bank details', $batches->sum('excluded_customer_count'), $batches->sum('excluded_total_laari')]);
        $sheet->push(['Wallet withdrawals in period', $withdrawals->count(), $withdrawals->sum('amount_laari')]);
        $sheet->push(['Wallet withdrawals — paid', $withdrawalsPaid['count'], $withdrawalsPaid['laari']]);
        $sheet->push(['Wallet withdrawals — failed or returned', $withdrawalsReturned['count'], $withdrawalsReturned['laari']]);
        $sheet->push(['Money out — cashback and wallet', null, $cashbackPaid + $withdrawalsPaid['laari']]);

        return $sheet;
    }

    /**
     * Cashback paid out per BUSINESS day — the dashboard chart's fourth
     * line, bucketed off the same paid event paidScope() drives on, so the
     * bars add up to what {@see self::paidTotals()} reports for the period.
     *
     * Sparse: days with no payout have no row.
     *
     * @return array<string, int> Y-m-d => laari
     */
    public function dailyPaid(): array
    {
        $rows = $this->paidScope()
            ->selectRaw('(paid_event.paid_at AT TIME ZONE ?)::date AS day', [$this->period->timezone])
            ->selectRaw('COALESCE(SUM(transactions.cashback_laari), 0) AS paid_out_laari')
            ->groupByRaw('1')
            ->get();

        $daily = [];

        foreach ($rows as $row) {
            $daily[(string) $row->day] = (int) $row->paid_out_laari;
        }

        return $daily;
    }

    /**
     * WHAT WENT OUT TO CUSTOMERS in the period, without a row per payment —
     * one aggregate over paidScope(), the very scope the Transactions sheet
     * is built from, so the dashboard's "paid out to customers" is the
     * report's own number rather than a second reading of the event log.
     *
     * @return array{count: int, cashback_laari: int}
     */
    public function paidTotals(): array
    {
        $row = $this->paidScope()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(transactions.cashback_laari), 0) AS cashback_laari')
            ->first();

        return [
            'count' => (int) $row->n,
            'cashback_laari' => (int) $row->cashback_laari,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $transactions = $this->sheet(self::TRANSACTIONS);
        $payouts = $this->sheet(self::PAYOUTS);
        $batches = $this->sheet(self::BATCHES);
        $withdrawals = $this->sheet(self::WITHDRAWALS);

        return [
            'transactions' => [
                'count' => $transactions->count(),
                'cashback_laari' => $transactions->sum('cashback_laari'),
            ],
            'payout_items' => [
                'count' => $payouts->count(),
                'amount_laari' => $payouts->sum('amount_laari'),
                'paid_laari' => $payouts->sum('paid_laari'),
            ],
            'batches' => [
                'count' => $batches->count(),
                'total_laari' => $batches->sum('total_laari'),
                'paid_laari' => $batches->sum('paid_laari'),
                'excluded_customer_count' => $batches->sum('excluded_customer_count'),
                'excluded_total_laari' => $batches->sum('excluded_total_laari'),
            ],
            'wallet_withdrawals' => [
                'count' => $withdrawals->count(),
                'amount_laari' => $withdrawals->sum('amount_laari'),
            ],
            // The tie, stated rather than implied: three sheets, one number.
            'ties' => [
                'transactions_cashback_laari' => $transactions->sum('cashback_laari'),
                'payout_items_paid_laari' => $payouts->sum('paid_laari'),
                'batches_paid_laari' => $batches->sum('paid_laari'),
            ],
        ];
    }
}
