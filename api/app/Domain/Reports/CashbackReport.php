<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Cashback\TransactionState;
use App\Domain\Settlement\SettlementState;
use App\Models\Settlement;
use App\Models\SettlementLine;
use Carbon\CarbonImmutable;
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
 *   Summary                 counts and totals by state, then the grand
 *                           total, then the same for the settlements the
 *                           period submitted.
 *   Transactions            one row per sale, with the exact laari each one
 *                           collected (see SettlementLineAllocation for why
 *                           that is not simply cashback + fee + GST).
 *   Settlements (money in)  one row per batch submitted in the period, with
 *                           the bank references it was matched on.
 *
 * REVERSED SALES ARE OUT BY DEFAULT (owner, 2026-08-24). A reversed sale
 * earned nobody anything and is owed by nobody; leaving it on the report
 * inflated the state breakdown and invited a reader to add it into what
 * merchants owe. `include_reversed` puts them back for whoever needs to see
 * what was reversed in a month.
 *
 * That exclusion cannot disturb the Collected column's tie to the bank,
 * and the reason is structural rather than lucky: §6 allows `reversed` only
 * from tracked, awaiting_validation, payable_unfunded and on_hold, and a
 * settlement line is allocated by CONFIRMING its transaction. So a row that
 * collected money is confirmed or paid, and a confirmed or paid row cannot
 * be reversed — corrections after confirmation are adjustments (§13).
 * Dropping reversed rows therefore never drops a laari of collection.
 * CashbackReportTest proves both halves: that the state machine refuses the
 * transition, and that Σ Collected == amount_received in both modes.
 */
final class CashbackReport extends BaseReport
{
    public const string TRANSACTIONS = 'Transactions';

    /**
     * The direction is in the title because "settlement" alone does not
     * carry it — a tax professional reading the tab cannot tell whether the
     * merchant is settling to us or we are settling to somebody. The domain
     * word stays so the sheet still matches the settlement queue in the
     * panel. 22 characters, inside Excel's 31.
     */
    public const string SETTLEMENTS = 'Settlements (money in)';

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
     *
     * The reversed-rows predicate lives HERE, not in the sheet, for the same
     * reason: countRows() decides whether the report is refused as too
     * large, and it must count the rows the report will actually contain.
     */
    private function transactionScope(): Builder
    {
        return DB::table('transactions')
            ->where('transactions.occurred_at', '>=', $this->period->start)
            ->where('transactions.occurred_at', '<', $this->period->end)
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
                // The ambiguous labels, disambiguated (owner, 2026-08-24).
                // "Collected" and "Settlement ref" both sit one column away
                // from "Payout batch" on the same row, and the two words
                // point in opposite directions: one is money the merchant
                // sent us, the other is money we sent the customer. The
                // KEYS are untouched — the panel reads those.
                ReportColumn::money('collected_laari', 'Collected from merchant'),
                ReportColumn::text('state', 'State'),
                ReportColumn::text('settlement_ref', 'Merchant settlement ref'),
                ReportColumn::text('funding_method', 'Funding'),
                ReportColumn::date('settled_at', 'Settled at'),
                ReportColumn::date('paid_at', 'Paid to customer at'),
                ReportColumn::text('payout_batch_ref', 'Customer payout batch'),
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
                $this->personName($row->customer_name),
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
                ReportColumn::text('reference', 'Merchant settlement ref'),
                ReportColumn::text('merchant', 'Merchant'),
                ReportColumn::text('state', 'State'),
                ReportColumn::text('funding_method', 'Funding'),
                ReportColumn::int('line_count', 'Lines'),
                ReportColumn::money('cashback_total_laari', 'Cashback'),
                ReportColumn::money('fee_total_laari', 'Fee'),
                ReportColumn::money('gst_total_laari', 'GST'),
                ReportColumn::money('amount_due_laari', 'Amount due from merchant'),
                ReportColumn::percent('discount_rate_bp', 'Discount rate'),
                ReportColumn::money('discount_laari', 'Discount'),
                ReportColumn::money('forgiveness_laari', 'Forgiveness'),
                ReportColumn::money('amount_received_laari', 'Received from merchant'),
                ReportColumn::date('submitted_at', 'Submitted at'),
                ReportColumn::date('settled_at', 'Settled at'),
                // The reconciliation columns (owner, 2026-08-24). The two
                // bank references are NOT the same fact and a reader
                // chasing an unmatched transfer needs both: one is what the
                // merchant typed into the settle page, the other is what
                // the bank actually called the money when it arrived. They
                // disagree often — of the eight live settlements, none
                // carry a merchant-typed ref and four carry a bank one.
                ReportColumn::text('bank_ref_merchant', 'Bank reference (merchant)'),
                ReportColumn::text('bank_ref_matched', 'Bank reference (matched)'),
                ReportColumn::text('matched_payer_name', 'Payer name (bank)'),
                ReportColumn::date('matched_at', 'Matched at'),
                ReportColumn::text('matched_by', 'Matched by'),
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

        $settlementIds = $this->settlementScope()->pluck('settlements.id')->map(intval(...))->all();

        $forgiven = SettlementLineAllocation::forgivenBySettlement($settlementIds);
        $payments = $this->matchedPayments($settlementIds);

        foreach ($rows as $row) {
            $matched = $payments[(int) $row->id] ?? null;

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
                $matched['merchant_refs'] ?? '',
                $matched['matched_refs'] ?? '',
                $matched['payer_names'] ?? '',
                $matched['matched_at'] ?? null,
                $matched['matched_by'] ?? '',
            ]);
        }

        return $sheet;
    }

    /**
     * The matched bank payments behind each settlement, aggregated to one
     * row's worth of cells (owner, 2026-08-24).
     *
     * ONE SETTLEMENT CAN HAVE SEVERAL. A merchant who pays in two transfers
     * has two matched payments against one batch; so does a merchant who
     * underpaid and topped up, and so does a receipt matched twice against
     * different bank rows. So every column here is a join, not a lookup —
     * a `->value()` would silently show the first transfer and hide the
     * rest, which for a reconciliation column is worse than showing none.
     *
     * Only `matched` payments. A pending payment is a claim the merchant
     * has made and nobody has checked; a rejected one is a claim that was
     * checked and refused. Neither is a bank reference this batch was
     * settled on, and printing either beside a settled amount would invite
     * somebody to reconcile against money that never arrived.
     *
     * `matched_trx_refs` (the jsonb identifier array the matcher captured)
     * is merged into the bank column and DEDUPED against `matched_trx_id`:
     * live rows carry the same id in both, and "90863673, 90863673" reads
     * as two transfers.
     *
     * @param  list<int>  $settlementIds
     * @return array<int, array{merchant_refs: string, matched_refs: string, payer_names: string, matched_at: CarbonImmutable|null, matched_by: string}>
     */
    private function matchedPayments(array $settlementIds): array
    {
        if ($settlementIds === []) {
            return [];
        }

        $rows = DB::table('settlement_payments')
            // The admin who matched by hand, by name. `matched_by` is the
            // answer to the question the column's own label asks, and it is
            // populated on every hand-matched live row — printing "Manual"
            // beside a timestamp while the row holds the identity throws
            // away the one fact an auditor is chasing.
            ->leftJoin('admin_users', 'admin_users.id', '=', 'settlement_payments.matched_by')
            ->whereIn('settlement_id', $settlementIds)
            ->where('settlement_payments.state', 'matched')
            ->orderBy('settlement_id')
            ->orderBy('settlement_payments.id')
            ->select([
                'settlement_id',
                'bank_ref',
                'matched_trx_id',
                'matched_trx_refs',
                'matched_payer_name',
                'matched_at',
                'auto_matched',
                'matched_by_rule',
                'admin_users.name as matched_by_name',
            ])
            ->get();

        /** @var array<int, array{merchant: list<string>, matched: list<string>, payers: list<string>, by: list<string>, at: CarbonImmutable|null}> $bySettlement */
        $bySettlement = [];

        foreach ($rows as $row) {
            $id = (int) $row->settlement_id;
            $bySettlement[$id] ??= ['merchant' => [], 'matched' => [], 'payers' => [], 'by' => [], 'at' => null];

            $this->collect($bySettlement[$id]['merchant'], (string) ($row->bank_ref ?? ''));
            $this->collect($bySettlement[$id]['matched'], (string) ($row->matched_trx_id ?? ''));

            foreach ($this->identifiers($row->matched_trx_refs) as $reference) {
                $this->collect($bySettlement[$id]['matched'], $reference);
            }

            // Through personName() like every other person's name in every
            // report: this is what the BANK called the payer of a merchant's
            // transfer, and live rows hold plain personal names. The .xlsx
            // carries it whole — a reconciliation column that cannot be
            // matched against a statement line is not a reconciliation
            // column — and the preview masks it, per name, BEFORE the
            // implode, so a two-payment settlement masks both.
            $this->collect($bySettlement[$id]['payers'], $this->personName($row->matched_payer_name));
            $this->collect($bySettlement[$id]['by'], $this->matchedBy(
                $row->auto_matched,
                $row->matched_by_rule,
                $row->matched_by_name,
            ));

            $matchedAt = $this->at($row->matched_at);

            if ($matchedAt !== null && ($bySettlement[$id]['at'] === null || $matchedAt->greaterThan($bySettlement[$id]['at']))) {
                $bySettlement[$id]['at'] = $matchedAt;
            }
        }

        $aggregated = [];

        foreach ($bySettlement as $id => $parts) {
            $aggregated[$id] = [
                'merchant_refs' => implode(', ', $parts['merchant']),
                'matched_refs' => implode(', ', $parts['matched']),
                'payer_names' => implode(', ', $parts['payers']),
                'matched_at' => $parts['at'],
                'matched_by' => implode(', ', $parts['by']),
            ];
        }

        return $aggregated;
    }

    /**
     * Appends a value to an aggregation bucket unless it is empty or
     * already there. Order of first appearance is kept, so the cell reads
     * in the order the payments were matched.
     *
     * @param  list<string>  $bucket
     */
    private function collect(array &$bucket, string $value): void
    {
        $value = trim($value);

        if ($value !== '' && ! in_array($value, $bucket, true)) {
            $bucket[] = $value;
        }
    }

    /**
     * `settlement_payments.matched_trx_refs` as a list of strings. It is
     * jsonb, so DB::table hands it back as raw JSON text; a malformed or
     * absent value is simply no identifiers rather than an exception, since
     * a report must never fail to render over a decoration column.
     *
     * @return list<string>
     */
    private function identifiers(mixed $refs): array
    {
        if ($refs === null || $refs === '') {
            return [];
        }

        $decoded = is_array($refs) ? $refs : json_decode((string) $refs, true);

        if (! is_array($decoded)) {
            return [];
        }

        $identifiers = [];

        foreach ($decoded as $reference) {
            if (is_string($reference) || is_int($reference)) {
                $identifiers[] = (string) $reference;
            }
        }

        return $identifiers;
    }

    /**
     * WHO matched this payment — the first question an auditor asks about a
     * reconciliation nobody remembers doing, and it wants a name.
     *
     * A hand match names the admin (`Manual — Ahmed Nazeeh`), because
     * `settlement_payments.matched_by` holds them and "Manual" beside a
     * timestamp reads as an identity that was lost. It falls back to bare
     * "Manual" for a row whose admin is null or deleted, which is a true
     * statement rather than a blank.
     *
     * An automatic match names the RULE instead, because "automatic" covers
     * rules of very different confidence (`receipt_reference` read an id off
     * the slip; an amount-and-window rule guessed).
     */
    private function matchedBy(mixed $autoMatched, mixed $rule, mixed $adminName = null): string
    {
        // Postgres booleans arrive as PHP bools through PDO, but this column
        // is read through the query builder rather than a cast model, and a
        // driver that hands back 't'/'f' would make (bool) say true for
        // BOTH. Enumerate the truths instead.
        $auto = $autoMatched === true
            || $autoMatched === 1
            || $autoMatched === '1'
            || $autoMatched === 't'
            || $autoMatched === 'true';

        if (! $auto) {
            // Masked on screen, whole in the workbook — an admin's name is a
            // person's name, and the preview is the render that gets
            // screenshotted into a support thread.
            $admin = $this->personName(is_string($adminName) ? $adminName : null);

            return $admin === '' ? 'Manual' : 'Manual — '.$admin;
        }

        $rule = trim((string) ($rule ?? ''));

        return $rule === '' ? 'Automatic' : 'Automatic — '.$rule;
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
        $sheet = new Sheet(
            self::SUMMARY,
            [
                ReportColumn::text('metric', 'Metric'),
                ReportColumn::int('count', 'Count'),
                ReportColumn::money('eligible_laari', 'Eligible sale'),
                ReportColumn::money('cashback_laari', 'Cashback'),
                ReportColumn::money('fee_laari', 'Fee'),
                ReportColumn::money('gst_laari', 'GST'),
                ReportColumn::money('gross_due_laari', 'Gross due'),
                ReportColumn::money('collected_laari', 'Collected from merchants'),
            ],
            header: $this->headerFor('Manfaa — cashback report', [
                'Every figure on this report is money IN or money owed TO Manfaa by merchants, '
                    .'except the Cashback column, which is what those merchants owe their customers and '
                    .'pay through us.',
                $this->options->includeReversed
                    ? 'Reversed sales ARE included in the counts and totals below; they earned nobody '
                        .'anything, so read the state breakdown before adding the grand total into anything.'
                    : 'Reversed sales are excluded. The earnings report still carries their ledger '
                        .'journals, and must: there, the reversal is the posting that TAKES the fee back '
                        .'out of income.',
            ]),
        );

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
