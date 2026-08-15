<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Money\Laari;
use App\Domain\Platform\BankAccountService;
use App\Models\Adjustment;
use App\Models\Merchant;

/**
 * What the merchant must know BEFORE they walk to their bank (PLAN §1
 * receipt-first): exactly what this selection will cost, where to send it,
 * and what to quote.
 *
 * Reservation-free on purpose. Nothing is claimed, no draft is created, no
 * reference is burnt — a merchant who previews and never transfers leaves no
 * trace, and their transactions stay eligible for anyone else's batch. The
 * price of that is that the reference is a PREVIEW: the final one is
 * assigned at submit. The AMOUNT, however, is computed by the same rules
 * SettlementBuilder uses (same eligible-transaction query, same stored line
 * integers, same FIFO §7 credit netting), so preview and settlement agree
 * unless the underlying rows change between the two calls.
 */
final readonly class SettlementPreview
{
    public function __construct(
        private SettlementBuilder $builder,
        private BankAccountService $bankAccounts,
    ) {}

    /**
     * @param  list<int>|null  $transactionIds  null previews everything eligible
     * @return array<string, mixed>
     *
     * @throws NotEligibleForSettlementException when a named transaction is not actually eligible
     */
    public function for(Merchant $merchant, ?array $transactionIds): array
    {
        $query = $this->builder->eligibleTransactions($merchant);

        if ($transactionIds !== null) {
            $query->whereIn('id', $transactionIds);
        }

        $transactions = $query->orderBy('due_at')->orderBy('id')->get();

        if ($transactionIds !== null) {
            $missing = array_values(array_diff(
                array_map(intval(...), $transactionIds),
                $transactions->pluck('id')->all(),
            ));

            if ($missing !== []) {
                throw NotEligibleForSettlementException::forTransactions($missing);
            }
        }

        if ($transactions->isEmpty()) {
            throw NotEligibleForSettlementException::nothingToSettle($merchant);
        }

        // The batch totals are SUMS of the stored transaction integers —
        // exactly what SettlementBuilder snapshots onto the lines. Nothing
        // here is recomputed from a rate.
        $cashback = (int) $transactions->sum('cashback_laari');
        $fee = (int) $transactions->sum('fee_laari');
        $gst = (int) $transactions->sum('fee_gst_laari');
        $lineTotal = $cashback + $fee + $gst;

        $credit = $this->pendingCreditFor($merchant, $lineTotal);
        $due = $lineTotal - $credit;

        $account = $this->bankAccounts->activePrimaryDetails();

        return [
            'transaction_ids' => array_map(intval(...), $transactions->pluck('id')->all()),
            'transaction_count' => $transactions->count(),
            'sale_total_laari' => (int) $transactions->sum('eligible_laari'),
            'cashback_total_laari' => $cashback,
            'fee_total_laari' => $fee,
            'fee_gst_total_laari' => $gst,
            'line_total_laari' => $lineTotal,
            'credit_applied_laari' => $credit,
            'credit_applied_mvr' => Laari::of($credit)->formatMvr(),
            'amount_due_laari' => $due,
            'amount_due_mvr' => Laari::of($due)->formatMvr(),
            'due_at' => $transactions->min('due_at')?->toIso8601String(),
            'payment_instructions' => [
                // Reservation-free: this is what the reference WOULD be. The
                // batch's real reference is assigned when the receipt is
                // submitted, and it is the one the merchant should quote if
                // the two ever differ.
                'reference_preview' => $this->builder->peekReference(),
                'reference_is_final' => false,
                'amount_due_laari' => $due,
                'amount_due_mvr' => Laari::of($due)->formatMvr(),
                'bank_account' => $account === null ? null : [
                    'bank_name' => $account['bank_name'],
                    'account_no' => $account['account_no'],
                    'account_name' => $account['account_name'],
                ],
                'needs_configuration' => $account === null,
            ],
        ];
    }

    /**
     * The §7 credit that would net into this batch, by the same strict-FIFO
     * walk SettlementBuilder::applyPendingAdjustments performs: memos apply
     * in creation order and application STOPS at the first one larger than
     * what remains due — a batch never leaves creation owing the merchant
     * money, and the tail waits for a later batch.
     */
    private function pendingCreditFor(Merchant $merchant, int $lineTotal): int
    {
        $due = $lineTotal;
        $applied = 0;

        // Read-only: no lock, so a preview never blocks a real submission.
        foreach ($this->builder->applicablePendingAdjustments($merchant->id)->get() as $adjustment) {
            /** @var Adjustment $adjustment */
            $credit = -$adjustment->amount_laari;

            if ($credit > $due) {
                break;
            }

            $due -= $credit;
            $applied += $credit;
        }

        return $applied;
    }
}
