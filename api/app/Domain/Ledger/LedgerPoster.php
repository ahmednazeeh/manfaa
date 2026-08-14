<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerJournal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts one balanced journal per call. A line is an associative array:
 * ['account' => AccountCode, 'debit_laari' => int, 'credit_laari' => int,
 * 'currency' => string] — omitted amounts default to 0, currency to MVR.
 *
 * All validation runs before anything is written, and the writes share one
 * DB transaction, so a rejected post leaves no partial journal behind.
 */
final class LedgerPoster
{
    private const string DEFAULT_CURRENCY = 'MVR';

    /**
     * @param  list<array{account: AccountCode, debit_laari?: int, credit_laari?: int, currency?: string}>  $lines
     * @return int the id of the created ledger_journals row
     */
    public function post(string $referenceType, int $referenceId, string $description, array $lines): int
    {
        $this->assertLinesValid($lines);
        $this->assertBalancedPerCurrency($lines);

        return DB::transaction(function () use ($referenceType, $referenceId, $description, $lines): int {
            $accountIds = $this->accountIdsByCode($lines);

            $journal = LedgerJournal::query()->create([
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'posted_at' => CarbonImmutable::now('UTC'),
            ]);

            foreach ($lines as $line) {
                LedgerEntry::query()->create([
                    'journal_id' => $journal->id,
                    'account_id' => $accountIds[$line['account']->value],
                    'debit_laari' => $line['debit_laari'] ?? 0,
                    'credit_laari' => $line['credit_laari'] ?? 0,
                    'currency' => $line['currency'] ?? self::DEFAULT_CURRENCY,
                ]);
            }

            return $journal->id;
        });
    }

    /**
     * @param  list<array{account: AccountCode, debit_laari?: int, credit_laari?: int, currency?: string}>  $lines
     */
    private function assertLinesValid(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidJournalLineException('A journal needs at least two entry lines.');
        }

        foreach ($lines as $index => $line) {
            $debit = $line['debit_laari'] ?? 0;
            $credit = $line['credit_laari'] ?? 0;

            if ($debit < 0 || $credit < 0) {
                throw new InvalidJournalLineException(
                    sprintf('Line %d carries a negative amount (debit %d, credit %d).', $index, $debit, $credit)
                );
            }

            // Exactly one side must be positive; this rejects both-zero and both-positive lines.
            if (($debit > 0) === ($credit > 0)) {
                throw new InvalidJournalLineException(sprintf(
                    'Line %d must have exactly one of debit or credit greater than zero (debit %d, credit %d).',
                    $index,
                    $debit,
                    $credit,
                ));
            }
        }
    }

    /**
     * @param  list<array{account: AccountCode, debit_laari?: int, credit_laari?: int, currency?: string}>  $lines
     */
    private function assertBalancedPerCurrency(array $lines): void
    {
        $netByCurrency = [];

        foreach ($lines as $line) {
            $currency = $line['currency'] ?? self::DEFAULT_CURRENCY;
            $netByCurrency[$currency] ??= 0;
            $netByCurrency[$currency] += ($line['debit_laari'] ?? 0) - ($line['credit_laari'] ?? 0);
        }

        foreach ($netByCurrency as $currency => $net) {
            if ($net !== 0) {
                throw new UnbalancedJournalException(sprintf(
                    'Journal does not balance for %s: debits minus credits = %d laari.',
                    $currency,
                    $net,
                ));
            }
        }
    }

    /**
     * @param  list<array{account: AccountCode, debit_laari?: int, credit_laari?: int, currency?: string}>  $lines
     * @return array<string, int> account id keyed by AccountCode value
     */
    private function accountIdsByCode(array $lines): array
    {
        $codes = array_values(array_unique(array_map(
            static fn (array $line): string => $line['account']->value,
            $lines,
        )));

        $ids = LedgerAccount::query()
            ->whereIn('code', $codes)
            ->pluck('id', 'code')
            ->all();

        foreach ($codes as $code) {
            if (! isset($ids[$code])) {
                throw new UnknownLedgerAccountException(
                    sprintf('Ledger account %s is not seeded.', $code)
                );
            }
        }

        return $ids;
    }
}
