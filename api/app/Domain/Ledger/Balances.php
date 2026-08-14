<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Illuminate\Support\Facades\DB;

/**
 * Read-side balance queries over ledger_entries.
 *
 * Convention: accountBalance() returns the signed raw sum of debit − credit.
 * Debit-normal accounts (asset, expense) read positive in normal life;
 * credit-normal accounts (liability, income) read negative. naturalBalance()
 * flips the sign for credit-normal accounts so every account reads positive
 * at its normal balance.
 */
final class Balances
{
    public function accountBalance(AccountCode $code): int
    {
        return (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_accounts.code', $code->value)
            ->sum(DB::raw('debit_laari - credit_laari'));
    }

    public function naturalBalance(AccountCode $code): int
    {
        $raw = $this->accountBalance($code);

        return $code->isDebitNormal() ? $raw : -$raw;
    }

    /**
     * Every seeded account with its totals, keyed by account code. The raw
     * balance_laari column sums to zero across the whole array whenever the
     * ledger is consistent. Note PHP casts the numeric codes to int keys.
     *
     * @return array<int|string, array{name: string, type: string, debit_laari: int, credit_laari: int, balance_laari: int}>
     */
    public function trialBalance(): array
    {
        $rows = DB::table('ledger_accounts')
            ->leftJoin('ledger_entries', 'ledger_entries.account_id', '=', 'ledger_accounts.id')
            ->groupBy('ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type')
            ->orderBy('ledger_accounts.code')
            ->selectRaw(<<<'SQL'
                ledger_accounts.code,
                ledger_accounts.name,
                ledger_accounts.type,
                COALESCE(SUM(ledger_entries.debit_laari), 0) AS debit_laari,
                COALESCE(SUM(ledger_entries.credit_laari), 0) AS credit_laari,
                COALESCE(SUM(ledger_entries.debit_laari - ledger_entries.credit_laari), 0) AS balance_laari
                SQL)
            ->get();

        $trial = [];

        foreach ($rows as $row) {
            $trial[$row->code] = [
                'name' => $row->name,
                'type' => $row->type,
                'debit_laari' => (int) $row->debit_laari,
                'credit_laari' => (int) $row->credit_laari,
                'balance_laari' => (int) $row->balance_laari,
            ];
        }

        return $trial;
    }

    /**
     * The §5 invariant, checked in one grouped query: no journal may have a
     * per-currency debit total that differs from its credit total.
     */
    public function journalsAllBalance(): bool
    {
        return DB::table('ledger_entries')
            ->select('journal_id')
            ->groupBy('journal_id', 'currency')
            ->havingRaw('SUM(debit_laari) <> SUM(credit_laari)')
            ->doesntExist();
    }
}
