<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\ReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The daily reconciliation (§5 invariant + §12 Phase 1), recomputed from
 * scratch on every run:
 *
 * 1. Every journal balances — one grouped SQL pass over ledger_entries.
 * 2. Key balances derived from the transactions table alone must equal what
 *    the ledger reports, per the §8 posting catalogue:
 *    - Merchant Receivable = Σ(cashback+fee+gst) over states that have
 *      accrued but not yet been settled, reversed, or written off
 *      (tracked, awaiting_validation, payable_unfunded, on_hold).
 *    - Customer Cashback Liability = Σ(cashback) over those states plus
 *      confirmed — the liability is released only at payout, reversal, or
 *      write-off.
 *    - Platform Fee Revenue = Σ(fee) over everything except reversed —
 *      write-off charges bad debt, it never claws back revenue.
 *    - APPLIED adjustments (§7 locked-line reversals netted into a batch)
 *      moved the ledger with their application-time credit journal
 *      (applyAdjustmentCredit) while the underlying transaction kept its
 *      state, so the derivation must mirror what that journal touches:
 *      their (negative) fee component offsets revenue outright, and their
 *      receivable credit offsets the receivable until the netted batch's
 *      allocations consume it — credit-first, exactly as
 *      SettlementAllocator funds allocations. The cashback share is
 *      deliberately NOT mirrored into the liability: application charges
 *      Platform-Funded Rewards Expense, never the liability — the
 *      customer's reward survives an adjustment and is released only at
 *      payout, reversal, or write-off, so the liability must keep tracking
 *      the transaction states alone.
 *
 * Every run writes one append-only reconciliation_runs row; a divergent run
 * carries the exact issues found.
 *
 * The run also surfaces stale holds — on_hold rows unchanged for over 30
 * days (StaleHolds). They are review backlog, not corruption: the issue is
 * recorded so nobody can miss it, but the run's status stays 'ok' and no
 * automatic transition ever touches a held row.
 */
final readonly class Reconciler
{
    public function __construct(private Balances $balances, private StaleHolds $staleHolds) {}

    public function run(): ReconciliationRun
    {
        $now = CarbonImmutable::now('UTC');
        $issues = [];

        $journalsChecked = (int) DB::table('ledger_journals')->count();

        foreach ($this->unbalancedJournals() as $row) {
            $issues[] = [
                'kind' => 'unbalanced_journal',
                'journal_id' => (int) $row->journal_id,
                'currency' => $row->currency,
                'debit_laari' => (int) $row->debit_laari,
                'credit_laari' => (int) $row->credit_laari,
            ];
        }

        $totals = $this->derivedVersusLedger();

        foreach ($totals as $account => $pair) {
            if ($pair['derived_laari'] !== $pair['ledger_laari']) {
                $issues[] = [
                    'kind' => 'balance_mismatch',
                    'account' => $account,
                    'derived_laari' => $pair['derived_laari'],
                    'ledger_laari' => $pair['ledger_laari'],
                ];
            }
        }

        // Stale holds are appended last and never flip the status: only the
        // invariant checks above decide ok versus divergent.
        if (($staleHolds = $this->staleHoldIssue($now)) !== null) {
            $issues[] = $staleHolds;
        }

        $divergent = collect($issues)->contains(fn (array $issue) => $issue['kind'] !== 'stale_holds');

        return ReconciliationRun::query()->create([
            'ran_at' => $now,
            'status' => $divergent ? 'divergent' : 'ok',
            'journals_checked' => $journalsChecked,
            'issues' => $issues === [] ? null : $issues,
            'totals' => $totals,
        ]);
    }

    /**
     * @return array<string, mixed>|null the stale_holds issue, or null when none
     */
    private function staleHoldIssue(CarbonImmutable $now): ?array
    {
        $rows = $this->staleHolds->query($now)
            ->orderBy('id')
            ->selectRaw('id, cashback_laari + fee_laari + fee_gst_laari AS laari')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'kind' => 'stale_holds',
            'count' => $rows->count(),
            'total_laari' => (int) $rows->sum('laari'),
            'transactions' => $rows
                ->map(fn (object $row) => ['id' => (int) $row->id, 'laari' => (int) $row->laari])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function unbalancedJournals()
    {
        return DB::table('ledger_entries')
            ->groupBy('journal_id', 'currency')
            ->havingRaw('SUM(debit_laari) <> SUM(credit_laari)')
            ->orderBy('journal_id')
            ->selectRaw(<<<'SQL'
                journal_id,
                currency,
                SUM(debit_laari) AS debit_laari,
                SUM(credit_laari) AS credit_laari
                SQL)
            ->get();
    }

    /**
     * @return array<string, array{derived_laari: int, ledger_laari: int}>
     */
    private function derivedVersusLedger(): array
    {
        $derived = DB::table('transactions')
            ->selectRaw(<<<'SQL'
                COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari) FILTER (
                    WHERE state IN ('tracked', 'awaiting_validation', 'payable_unfunded', 'on_hold')
                ), 0) AS receivable_laari,
                COALESCE(SUM(cashback_laari) FILTER (
                    WHERE state IN ('tracked', 'awaiting_validation', 'payable_unfunded', 'on_hold', 'confirmed')
                ), 0) AS liability_laari,
                COALESCE(SUM(fee_laari) FILTER (WHERE state <> 'reversed'), 0) AS revenue_laari
                SQL)
            ->first();

        // Applied adjustments store NEGATIVE component integers; summing the
        // fee in offsets revenue by exactly what the application-time credit
        // journal debited. The cashback share touched Platform-Funded
        // Rewards Expense, not the liability — no liability mirror exists.
        $applied = DB::table('adjustments')
            ->where('state', 'applied')
            ->selectRaw('COALESCE(SUM(fee_laari), 0) AS fee_laari')
            ->first();

        // The receivable side of an applied credit is consumed as the netted
        // batch's lines allocate (credit before cash, mirroring
        // SettlementAllocator); only the unconsumed remainder still offsets
        // the receivable.
        $unconsumed = DB::selectOne(<<<'SQL'
            SELECT COALESCE(SUM(GREATEST(per_settlement.credit_laari - per_settlement.allocated_laari, 0)), 0) AS laari
            FROM (
                SELECT
                    -SUM(adjustments.amount_laari) AS credit_laari,
                    COALESCE((
                        SELECT SUM(settlement_lines.cashback_laari + settlement_lines.fee_laari + settlement_lines.fee_gst_laari)
                        FROM settlement_lines
                        WHERE settlement_lines.settlement_id = adjustments.settlement_id
                          AND settlement_lines.allocated_at IS NOT NULL
                    ), 0) AS allocated_laari
                FROM adjustments
                WHERE adjustments.state = 'applied'
                GROUP BY adjustments.settlement_id
            ) AS per_settlement
            SQL);

        return [
            'receivable' => [
                'derived_laari' => (int) $derived->receivable_laari - (int) $unconsumed->laari,
                'ledger_laari' => $this->balances->accountBalance(AccountCode::MerchantReceivable),
            ],
            'liability' => [
                'derived_laari' => (int) $derived->liability_laari,
                'ledger_laari' => $this->balances->naturalBalance(AccountCode::CustomerCashbackLiability),
            ],
            'revenue' => [
                'derived_laari' => (int) $derived->revenue_laari + (int) $applied->fee_laari,
                'ledger_laari' => $this->balances->naturalBalance(AccountCode::PlatformFeeRevenue),
            ],
        ];
    }
}
