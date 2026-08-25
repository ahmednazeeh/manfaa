<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Money\Laari;
use App\Domain\Platform\BankAccountService;
use App\Models\Adjustment;
use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

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
 * integers, same FIFO §7 credit netting, same PLAN §1 discount evaluation),
 * so preview and settlement agree unless the underlying rows change between
 * the two calls.
 *
 * It also feeds the settlement PICKER, and does so with money the server
 * computed: every eligible transaction as a row (with its age in days), the
 * totals for the current selection, the bucket totals behind the filter
 * presets, and the discount evaluation. The panel sums rows to keep
 * checkbox-clicking instant, but the figure a merchant transfers is always a
 * figure this endpoint produced — a UI must never derive money from a rate.
 *
 * The discount here is ADVISORY. It is re-decided at submit, under the row
 * lock, and never accepted as an input.
 */
final readonly class SettlementPreview
{
    /**
     * The picker's filter presets. Ages are whole business-timezone days
     * since clock_start_at (§13), so `older_than_5` is "day 6 and beyond" —
     * the same boundary the dashboard's 0–5 / 6–10 / 11–15 buckets use.
     */
    private const array PRESETS = ['all', 'older_than_5', 'older_than_10', 'overdue'];

    public function __construct(
        private SettlementBuilder $builder,
        private BankAccountService $bankAccounts,
        private PromptDiscount $discounts,
    ) {}

    /**
     * @param  list<int>|null  $transactionIds  null previews everything eligible
     * @return array<string, mixed>
     *
     * @throws NotEligibleForSettlementException when a named transaction is not actually eligible
     */
    public function for(Merchant $merchant, ?array $transactionIds): array
    {
        $now = CarbonImmutable::now('UTC');

        // Everything the merchant COULD settle right now — the picker's full
        // row set, and the base for the preset buckets. The selection is a
        // subset of it, so one query answers both and the two can never
        // disagree about what exists.
        $eligible = $this->builder->eligibleTransactions($merchant)
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $selected = $this->select($eligible, $transactionIds);

        if ($selected->isEmpty()) {
            throw NotEligibleForSettlementException::nothingToSettle($merchant);
        }

        // The batch totals are SUMS of the stored transaction integers —
        // exactly what SettlementBuilder snapshots onto the lines. Nothing
        // here is recomputed from a rate.
        $cashback = (int) $selected->sum('cashback_laari');
        $fee = (int) $selected->sum('fee_laari');
        $gst = (int) $selected->sum('fee_gst_laari');
        $lineTotal = $cashback + $fee + $gst;

        $credit = $this->pendingCreditFor($merchant, $lineTotal);
        $dueBeforeDiscount = $lineTotal - $credit;

        // PLAN §1, advisory: the same evaluation submit() will run again for
        // real, against the same rules, capped the same way.
        $discount = $this->discounts->evaluate($merchant, $selected, $now)->cappedTo($dueBeforeDiscount);
        $due = $dueBeforeDiscount - $discount->discountLaari;

        $accounts = $this->bankAccounts->activeAccounts();
        // The default to show, and the answer to "is anything configured".
        // Keyed on the LIST, not on a primary flag: with an account per bank
        // and the merchant choosing, an estate where nobody happened to tick
        // primary is fully configured — and telling that merchant to contact
        // Manfaa while two accounts sit there would be a lie the platform
        // told itself.
        $account = $accounts === [] ? null : ($accounts[0] ?? null);
        $selectedIds = $this->ids($selected);

        return [
            'as_of' => $now->toIso8601String(),
            'transaction_ids' => $selectedIds,
            'transaction_count' => $selected->count(),
            'sale_total_laari' => (int) $selected->sum('eligible_laari'),
            'cashback_total_laari' => $cashback,
            // Manfaa's own charge and the tax on it, as SEPARATE figures
            // (owner, 2026-08-24) — never one blended number. The merchant
            // owes cashback + fee + GST, which is line_total_laari before
            // credits and the discount. Both are sums of stored per-row
            // integers, each row taxed at the rate stamped on it.
            'fee_total_laari' => $fee,
            'fee_total_mvr' => Laari::of($fee)->formatMvr(),
            'fee_gst_total_laari' => $gst,
            'fee_gst_total_mvr' => Laari::of($gst)->formatMvr(),
            'line_total_laari' => $lineTotal,
            'credit_applied_laari' => $credit,
            'credit_applied_mvr' => Laari::of($credit)->formatMvr(),
            'discount_laari' => $discount->discountLaari,
            'discount_mvr' => Laari::of($discount->discountLaari)->formatMvr(),
            'amount_due_before_discount_laari' => $dueBeforeDiscount,
            'amount_due_laari' => $due,
            'amount_due_mvr' => Laari::of($due)->formatMvr(),
            'due_at' => $selected->min('due_at')?->toIso8601String(),
            // Advisory: re-decided at submit and never sent back to us.
            'discount' => $discount->toArray(),
            // Every eligible row, so the picker can total a selection
            // client-side without a round trip per checkbox — the server's
            // own totals above remain the authority on what to transfer.
            'transactions' => $this->rows($eligible, $selectedIds, $now),
            'buckets' => $this->buckets($eligible, $now),
            'payment_instructions' => [
                // NO reference here, deliberately (owner decision
                // 2026-08-18). There is no settlement yet — it is created
                // when the receipt lands — so any number quoted now is a
                // guess the next submitter can take, and the merchant would
                // have written it on a bank transfer by then. The reference
                // appears once, on the settlement that actually exists.
                'amount_due_laari' => $due,
                'amount_due_mvr' => Laari::of($due)->formatMvr(),
                // The bill, itemised, on the screen the merchant reads
                // before walking to the bank: what the customers earned,
                // what Manfaa charged, and the tax on that charge.
                'cashback_total_laari' => $cashback,
                'fee_total_laari' => $fee,
                'fee_gst_total_laari' => $gst,
                'bank_account' => $account === null ? null : [
                    'bank_name' => $account['bank_name'],
                    'account_no' => $account['account_no'],
                    'account_name' => $account['account_name'],
                ],
                // Every account the merchant may send to, one per bank, the
                // default first. `bank_account` above is that default and
                // stays for clients that only ever showed one; a panel that
                // offers the choice reads this and sends back the id it used,
                // so the receipt can be reconciled against the right
                // statement rather than against whichever account happened to
                // be primary when the screen loaded.
                'bank_accounts' => $accounts,
                'needs_configuration' => $account === null,
            ],
        ];
    }

    /**
     * The named subset, or everything. A named transaction that is not
     * actually eligible is a refusal, never a silent drop — the merchant
     * would otherwise transfer for a batch that quietly lost a line.
     *
     * @param  Collection<int, Transaction>  $eligible
     * @param  list<int>|null  $transactionIds
     * @return Collection<int, Transaction>
     */
    private function select(Collection $eligible, ?array $transactionIds): Collection
    {
        if ($transactionIds === null) {
            return $eligible;
        }

        $wanted = array_map(intval(...), $transactionIds);
        $selected = $eligible->whereIn('id', $wanted)->values();

        $missing = array_values(array_diff($wanted, $this->ids($selected)));

        if ($missing !== []) {
            throw NotEligibleForSettlementException::forTransactions($missing);
        }

        return $selected;
    }

    /**
     * One row per eligible transaction: the identity the merchant recognises,
     * the two dates the age rules turn on, and the stored money integers with
     * their sum. `age_days` and `overdue` are computed here, in the business
     * timezone (§13), so the panel never re-derives a date rule either.
     *
     * @param  Collection<int, Transaction>  $eligible
     * @param  list<int>  $selectedIds
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $eligible, array $selectedIds, CarbonImmutable $now): array
    {
        $timezone = TransactionAge::timezone();
        $selected = array_flip($selectedIds);

        return $eligible->map(function (Transaction $transaction) use ($selected, $now, $timezone): array {
            $due = $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;

            return [
                'id' => (int) $transaction->id,
                'invoice_no' => $transaction->invoice_no,
                'occurred_at' => $transaction->occurred_at?->toIso8601String(),
                'clock_start_at' => $transaction->clock_start_at?->toIso8601String(),
                'due_at' => $transaction->due_at?->toIso8601String(),
                'age_days' => TransactionAge::days($transaction->clock_start_at, $now, $timezone),
                'overdue' => $this->isOverdue($transaction, $now),
                'cashback_laari' => $transaction->cashback_laari,
                'fee_laari' => $transaction->fee_laari,
                'fee_gst_laari' => $transaction->fee_gst_laari,
                'due_laari' => $due,
                'due_mvr' => Laari::of($due)->formatMvr(),
                'selected' => isset($selected[$transaction->id]),
            ];
        })->all();
    }

    /**
     * Counts, totals and membership for each filter preset, over everything
     * eligible. The picker switches presets by sending the ids back as a
     * selection — it never has to work out which rows a preset contains, and
     * never has to add up what they cost.
     *
     * @param  Collection<int, Transaction>  $eligible
     * @return array<string, array<string, mixed>>
     */
    private function buckets(Collection $eligible, CarbonImmutable $now): array
    {
        $timezone = TransactionAge::timezone();
        $buckets = [];

        foreach (self::PRESETS as $preset) {
            $rows = $eligible->filter(fn (Transaction $transaction): bool => $this->inPreset(
                $preset,
                TransactionAge::days($transaction->clock_start_at, $now, $timezone),
                $this->isOverdue($transaction, $now),
            ));

            $due = (int) $rows->sum('cashback_laari') + (int) $rows->sum('fee_laari') + (int) $rows->sum('fee_gst_laari');

            $buckets[$preset] = [
                'count' => $rows->count(),
                'cashback_laari' => (int) $rows->sum('cashback_laari'),
                'fee_laari' => (int) $rows->sum('fee_laari'),
                'fee_gst_laari' => (int) $rows->sum('fee_gst_laari'),
                'due_laari' => $due,
                'due_mvr' => Laari::of($due)->formatMvr(),
                'transaction_ids' => $this->ids($rows),
            ];
        }

        return $buckets;
    }

    private function inPreset(string $preset, int $ageDays, bool $overdue): bool
    {
        return match ($preset) {
            'older_than_5' => $ageDays > 5,
            'older_than_10' => $ageDays > 10,
            'overdue' => $overdue,
            default => true,
        };
    }

    private function isOverdue(Transaction $transaction, CarbonImmutable $now): bool
    {
        return $transaction->due_at !== null && $transaction->due_at->isBefore($now);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<int>
     */
    private function ids(Collection $transactions): array
    {
        return array_values(array_map(intval(...), $transactions->pluck('id')->all()));
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
