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
 *
 * Every run writes one append-only reconciliation_runs row; a divergent run
 * carries the exact issues found.
 */
final readonly class Reconciler
{
    public function __construct(private Balances $balances) {}

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

        return ReconciliationRun::query()->create([
            'ran_at' => $now,
            'status' => $issues === [] ? 'ok' : 'divergent',
            'journals_checked' => $journalsChecked,
            'issues' => $issues === [] ? null : $issues,
            'totals' => $totals,
        ]);
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

        return [
            'receivable' => [
                'derived_laari' => (int) $derived->receivable_laari,
                'ledger_laari' => $this->balances->accountBalance(AccountCode::MerchantReceivable),
            ],
            'liability' => [
                'derived_laari' => (int) $derived->liability_laari,
                'ledger_laari' => $this->balances->naturalBalance(AccountCode::CustomerCashbackLiability),
            ],
            'revenue' => [
                'derived_laari' => (int) $derived->revenue_laari,
                'ledger_laari' => $this->balances->naturalBalance(AccountCode::PlatformFeeRevenue),
            ],
        ];
    }
}
