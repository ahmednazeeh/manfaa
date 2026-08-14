<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\TransactionState;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Builds settlement batches (§6, §7). A draft is a mutable selection of the
 * merchant's payable_unfunded transactions; every total is a SUM of the
 * stored line snapshot integers — nothing is ever recomputed from a rate.
 * The moment a settlement leaves draft its lines freeze.
 */
final class SettlementBuilder
{
    /**
     * Transactions a settlement may pick up: the merchant's payable_unfunded
     * rows not already sitting on a non-cancelled settlement (a draft holds
     * its selection too, so two drafts can never claim the same line).
     *
     * @return Builder<Transaction>
     */
    public function eligibleTransactions(Merchant $merchant): Builder
    {
        return Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->where('state', TransactionState::PayableUnfunded->value)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('settlement_lines')
                    ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
                    ->whereColumn('settlement_lines.transaction_id', 'transactions.id')
                    ->where('settlements.state', '!=', SettlementState::Cancelled->value);
            });
    }

    /**
     * @param  list<int>|null  $transactionIds  null settles everything eligible
     */
    public function createDraft(Merchant $merchant, ?array $transactionIds = null): Settlement
    {
        return DB::transaction(function () use ($merchant, $transactionIds): Settlement {
            $transactions = $this->claimEligible($merchant, $transactionIds);

            if ($transactions === []) {
                throw NotEligibleForSettlementException::nothingToSettle($merchant);
            }

            $settlement = Settlement::query()->create([
                'merchant_id' => $merchant->id,
                'reference' => $this->nextReference(),
                'state' => SettlementState::Draft,
                'funding_method' => 'bank',
                'currency' => 'MVR',
            ]);

            $this->snapshotLines($settlement, $transactions);
            $this->refreshTotals($settlement);

            return $settlement->refresh();
        });
    }

    /**
     * Draft-only: pulls more eligible transactions onto the batch.
     *
     * @param  list<int>  $transactionIds
     */
    public function addLines(Settlement $settlement, array $transactionIds): Settlement
    {
        return DB::transaction(function () use ($settlement, $transactionIds): Settlement {
            $settlement = $this->locked($settlement);
            $this->assertDraft($settlement);

            $transactions = $this->claimEligible($settlement->merchant, $transactionIds);
            $this->snapshotLines($settlement, $transactions);
            $this->refreshTotals($settlement);

            return $settlement->refresh();
        });
    }

    /**
     * Draft-only: drops a line from the batch; the transaction becomes
     * eligible again immediately.
     */
    public function removeLine(Settlement $settlement, SettlementLine $line): Settlement
    {
        return DB::transaction(function () use ($settlement, $line): Settlement {
            $settlement = $this->locked($settlement);
            $this->assertDraft($settlement);

            if ($line->settlement_id !== $settlement->id) {
                throw NotEligibleForSettlementException::forTransactions([$line->transaction_id]);
            }

            $line->delete();
            $this->refreshTotals($settlement);

            return $settlement->refresh();
        });
    }

    /**
     * draft → awaiting_payment. From here on the lines are frozen and every
     * change to what the merchant owes is a new batch, never an edit.
     */
    public function submit(Settlement $settlement): Settlement
    {
        return DB::transaction(function () use ($settlement): Settlement {
            $settlement = $this->locked($settlement);

            if ($settlement->state !== SettlementState::Draft) {
                throw InvalidSettlementStateException::forAction($settlement, 'submit', 'a draft');
            }

            if (! $settlement->lines()->exists()) {
                throw NotEligibleForSettlementException::emptyDraft($settlement);
            }

            $settlement->forceFill(['state' => SettlementState::AwaitingPayment])->save();

            return $settlement;
        });
    }

    /**
     * Allowed only while no money has arrived: from draft, or from
     * awaiting_payment with zero received and no recorded payment.
     * Cancellation DELETES the batch's lines — nothing on them was ever
     * allocated — which is what releases the transactions back to
     * eligibility: settlement_lines.transaction_id is unique across live
     * batches, so a claim exists exactly as long as its line row does.
     */
    public function cancel(Settlement $settlement): Settlement
    {
        return DB::transaction(function () use ($settlement): Settlement {
            $settlement = $this->locked($settlement);

            if (! in_array($settlement->state, [SettlementState::Draft, SettlementState::AwaitingPayment], true)) {
                throw InvalidSettlementStateException::forAction($settlement, 'cancel', 'a draft or an unpaid awaiting_payment batch');
            }

            // Defensive: awaiting_payment cannot hold payments (recording one
            // moves the batch to payment_review), but a recorded or matched
            // payment must always block cancellation.
            if ($settlement->amount_received_laari !== 0 || $settlement->payments()->exists()) {
                throw InvalidSettlementStateException::cancelWithMoneyReceived($settlement);
            }

            $settlement->lines()->delete();
            $settlement->forceFill(['state' => SettlementState::Cancelled])->save();

            return $settlement;
        });
    }

    /**
     * Locks and returns the eligible rows, throwing when the caller named a
     * transaction that is not actually available.
     *
     * @param  list<int>|null  $transactionIds
     * @return list<Transaction>
     */
    private function claimEligible(Merchant $merchant, ?array $transactionIds): array
    {
        $query = $this->eligibleTransactions($merchant)->lockForUpdate();

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

        return $transactions->all();
    }

    /**
     * The unique index on settlement_lines.transaction_id is the authority
     * on claims: two concurrent drafts can both pass claimEligible's
     * NOT EXISTS check (the FOR UPDATE lock on the transactions row does not
     * re-run the subquery for a row nobody updated), but only one insert can
     * ever land — the loser surfaces as not-eligible instead of silently
     * double-invoicing the merchant.
     *
     * @param  list<Transaction>  $transactions
     */
    private function snapshotLines(Settlement $settlement, array $transactions): void
    {
        foreach ($transactions as $transaction) {
            try {
                $settlement->lines()->create([
                    'transaction_id' => $transaction->id,
                    'cashback_laari' => $transaction->cashback_laari,
                    'fee_laari' => $transaction->fee_laari,
                    'fee_gst_laari' => $transaction->fee_gst_laari,
                    'currency' => $transaction->currency,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw NotEligibleForSettlementException::forTransactions([$transaction->id]);
            }
        }
    }

    /**
     * Batch totals are SUMS of the stored line integers; the batch due date
     * is the earliest line's due date (§7), not the creation date.
     */
    private function refreshTotals(Settlement $settlement): void
    {
        $totals = DB::table('settlement_lines')
            ->join('transactions', 'transactions.id', '=', 'settlement_lines.transaction_id')
            ->where('settlement_lines.settlement_id', $settlement->id)
            ->selectRaw(<<<'SQL'
                COALESCE(SUM(settlement_lines.cashback_laari), 0) AS cashback_total,
                COALESCE(SUM(settlement_lines.fee_laari), 0) AS fee_total,
                COALESCE(SUM(settlement_lines.fee_gst_laari), 0) AS gst_total,
                COALESCE(SUM(transactions.eligible_laari), 0) AS eligible_total,
                MIN(transactions.due_at) AS earliest_due_at
                SQL)
            ->first();

        $settlement->forceFill([
            // The eligible base the batch covers — reference only, like
            // transactions.sale_laari; never used in any computation.
            'sale_total_laari' => (int) $totals->eligible_total,
            'cashback_total_laari' => (int) $totals->cashback_total,
            'fee_total_laari' => (int) $totals->fee_total,
            'fee_gst_total_laari' => (int) $totals->gst_total,
            'amount_due_laari' => (int) $totals->cashback_total + (int) $totals->fee_total + (int) $totals->gst_total,
            'due_at' => $totals->earliest_due_at,
        ])->save();
    }

    /**
     * ST-<year>-<seq> with the sequence restarting each business-timezone
     * year. An advisory lock scoped to the surrounding DB transaction
     * serialises generation; the unique constraint on reference backstops it.
     */
    private function nextReference(): string
    {
        $year = CarbonImmutable::now((string) config('app.business_timezone', 'Indian/Maldives'))->year;

        DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('settlements-reference-'.$year)]);

        $prefix = sprintf('ST-%d-', $year);

        $latest = Settlement::query()->where('reference', 'like', $prefix.'%')->max('reference');
        $next = $latest === null ? 1 : (int) substr((string) $latest, strlen($prefix)) + 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function locked(Settlement $settlement): Settlement
    {
        return Settlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertDraft(Settlement $settlement): void
    {
        if ($settlement->state !== SettlementState::Draft) {
            throw SettlementLockedException::linesFrozen($settlement);
        }
    }
}
