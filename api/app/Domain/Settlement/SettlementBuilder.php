<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\Postings;
use App\Models\Adjustment;
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
 *
 * §7 locked batches: a reversal that could not reverse in place became a
 * pending credit Adjustment. Creating a draft nets those adjustments in
 * (oldest first) as negative amounts reducing amount_due, marks them
 * applied with the settlement linkage, and posts the adjustment's credit
 * journal (applyAdjustmentCredit) at that moment — application time, never
 * creation time.
 */
final class SettlementBuilder
{
    public function __construct(
        private readonly Postings $postings,
        private readonly TransitionService $transitions,
        private readonly LineAllocator $allocator,
    ) {}

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
            $this->applyPendingAdjustments($settlement);

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

            // Defensive: removeLine on a draft carrying applied adjustments
            // can push the netted due below zero. A batch never leaves draft
            // owing the merchant money — add lines or drop the batch.
            if ($settlement->amount_due_laari < 0) {
                throw InvalidSettlementStateException::forAction($settlement, 'submit', 'a batch with a non-negative amount due');
            }

            // Fully netted by applied credits: there is nothing to await —
            // no payment path accepts zero laari (bank payments must be
            // positive, a zero wallet movement is refused), so an
            // awaiting_payment batch owing 0 would strand its transactions
            // payable_unfunded past due_at and auto-suspend a merchant with
            // zero net debt. The credit IS the funding: every line allocates
            // now (confirming the customers' cashback through the state
            // machine) and the batch settles. The applied credits' journals
            // posted at application, so no further posting belongs here.
            if ($settlement->amount_due_laari === 0) {
                $now = CarbonImmutable::now('UTC');

                foreach (SettlementLines::inAllocationOrder($settlement) as $line) {
                    $this->allocator->allocate($line, Actor::system(), $now);
                }

                $settlement->forceFill(['state' => SettlementState::Settled])->save();

                return $settlement;
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

            $this->unapplyAdjustments($settlement);

            $reversalMemos = $this->pendingReversalMemos($settlement);

            $settlement->lines()->delete();
            $settlement->forceFill(['state' => SettlementState::Cancelled])->save();

            $this->reverseReleasedMemoTransactions($reversalMemos);

            return $settlement;
        });
    }

    /**
     * Pending reversal-memo adjustments whose transactions this cancellation
     * is about to release, locked. The vendor's reversal could not reverse
     * these in place ONLY because the line was frozen here (§7); with the
     * freeze gone the memo must be settled up immediately — left pending, a
     * refunded sale would rejoin the next draft as a live line AND net its
     * own credit, confirming (and eventually paying) cashback on a sale the
     * customer returned; or reverse a second time if the vendor re-sends.
     *
     * @return list<Adjustment>
     */
    private function pendingReversalMemos(Settlement $settlement): array
    {
        return Adjustment::query()
            ->whereIn('transaction_id', $settlement->lines()->pluck('transaction_id'))
            ->where('state', 'pending')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    /**
     * The deferred half of the vendor's reversal, executed the moment the
     * settlement lock disappears: the transaction reverses in place through
     * the state machine, the accrual is mirrored from the STORED integers
     * (exactly what the in-place path would have posted), and the memo is
     * voided — its credit must never also net a future batch.
     *
     * @param  list<Adjustment>  $memos
     */
    private function reverseReleasedMemoTransactions(array $memos): void
    {
        $now = CarbonImmutable::now('UTC');

        foreach ($memos as $memo) {
            /** @var Transaction $transaction */
            $transaction = Transaction::query()->whereKey($memo->transaction_id)->lockForUpdate()->firstOrFail();

            if ($transaction->state !== TransactionState::PayableUnfunded) {
                continue; // Nothing on a cancellable batch was ever allocated; defensive only.
            }

            $this->transitions->reverse(
                $transaction,
                Actor::system(),
                $memo->reason_code,
                ['adjustment_id' => $memo->id, 'released_by_settlement_cancellation' => true],
            );

            $accrued = $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;

            if ($accrued > 0) {
                $this->postings->reverseAccrual(
                    $transaction->cashback_laari,
                    $transaction->fee_laari,
                    $transaction->fee_gst_laari,
                    referenceId: $transaction->id,
                );
            }

            $memo->forceFill(['state' => 'voided', 'voided_at' => $now])->save();
        }
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
     * amount_due additionally nets in the (negative) sum of the adjustments
     * already applied to this batch, so a draft edit never silently drops
     * an applied credit.
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

        $appliedCredit = (int) Adjustment::query()
            ->where('settlement_id', $settlement->id)
            ->where('state', 'applied')
            ->sum('amount_laari');

        $settlement->forceFill([
            // The eligible base the batch covers — reference only, like
            // transactions.sale_laari; never used in any computation.
            'sale_total_laari' => (int) $totals->eligible_total,
            'cashback_total_laari' => (int) $totals->cashback_total,
            'fee_total_laari' => (int) $totals->fee_total,
            'fee_gst_total_laari' => (int) $totals->gst_total,
            'amount_due_laari' => (int) $totals->cashback_total + (int) $totals->fee_total + (int) $totals->gst_total + $appliedCredit,
            'due_at' => $totals->earliest_due_at,
        ])->save();
    }

    /**
     * §7: a reversal against a locked line "becomes a credit adjustment on
     * the next batch" — this is the next batch. Pending adjustments for the
     * merchant net in FIFO (creation order) as negative amounts reducing
     * amount_due; each applied adjustment is stamped with the settlement
     * linkage and its ledger credit (Postings::applyAdjustmentCredit) posts
     * NOW, at application time, from the stored (negated) component
     * integers. Creation posted nothing — the adjustment was a memo until
     * this moment.
     *
     * Applicability: an adjustment nets in only once its transaction's fate
     * is sealed — already confirmed/paid, or frozen on a batch that can no
     * longer be cancelled (anything past awaiting_payment). While the
     * locking batch is still cancellable, applying the credit would let a
     * later cancellation release a transaction whose refund was already
     * credited elsewhere — the memo stays pending and nets a later batch
     * instead (the same way an over-large credit's tail waits).
     *
     * Strict FIFO: application stops at the first adjustment whose credit
     * exceeds the remaining due — a batch never leaves createDraft owing
     * the merchant money; the tail stays pending for a later batch.
     */
    private function applyPendingAdjustments(Settlement $settlement): void
    {
        $pending = Adjustment::query()
            ->join('transactions', 'transactions.id', '=', 'adjustments.transaction_id')
            ->where('transactions.merchant_id', $settlement->merchant_id)
            ->where('adjustments.state', 'pending')
            ->where(function ($query) {
                $query
                    ->whereIn('transactions.state', [TransactionState::Confirmed->value, TransactionState::Paid->value])
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('settlement_lines')
                            ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
                            ->whereColumn('settlement_lines.transaction_id', 'transactions.id')
                            ->whereNotIn('settlements.state', [
                                SettlementState::Draft->value,
                                SettlementState::AwaitingPayment->value,
                                SettlementState::Cancelled->value,
                            ]);
                    });
            })
            ->orderBy('adjustments.id')
            ->select('adjustments.*')
            ->lockForUpdate()
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $due = $settlement->amount_due_laari;
        $now = CarbonImmutable::now('UTC');

        foreach ($pending as $adjustment) {
            $credit = -$adjustment->amount_laari;

            if ($credit > $due) {
                break;
            }

            $due -= $credit;

            $adjustment->forceFill([
                'state' => 'applied',
                'settlement_id' => $settlement->id,
                'applied_at' => $now,
            ])->save();

            $this->postings->applyAdjustmentCredit(
                -$adjustment->cashback_laari,
                -$adjustment->fee_laari,
                -$adjustment->fee_gst_laari,
                referenceId: $adjustment->id,
            );
        }

        $settlement->forceFill(['amount_due_laari' => $due])->save();
    }

    /**
     * Cancellation undoes the netting: applied adjustments go back to
     * pending (they will net into the next batch instead), and each one's
     * application-time credit journal is neutralised with the exact mirror
     * (Postings::unapplyAdjustmentCredit) so the receivable is whole again —
     * the credit must not exist twice when the next batch re-applies it.
     */
    private function unapplyAdjustments(Settlement $settlement): void
    {
        $applied = Adjustment::query()
            ->where('settlement_id', $settlement->id)
            ->where('state', 'applied')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($applied as $adjustment) {
            $adjustment->forceFill([
                'state' => 'pending',
                'settlement_id' => null,
                'applied_at' => null,
            ])->save();

            $this->postings->unapplyAdjustmentCredit(
                -$adjustment->cashback_laari,
                -$adjustment->fee_laari,
                -$adjustment->fee_gst_laari,
                referenceId: $adjustment->id,
            );
        }
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
