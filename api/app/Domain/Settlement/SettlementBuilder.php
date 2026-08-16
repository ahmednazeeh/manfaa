<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Models\Adjustment;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\SettlementPayment;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *
 * Receipt-first (PLAN §1 "Settlement flow"): the merchant-driven entry point
 * is createAndSubmitWithReceipt — build, freeze and evidence in ONE database
 * transaction, landing directly in payment_review. createDraft/submit stay
 * public because the composite paths and the documented admin fallback are
 * built out of them, but no merchant HTTP route reaches them on their own:
 * a settlement the platform cannot see a receipt for does not get created.
 */
final class SettlementBuilder
{
    public function __construct(
        private readonly Postings $postings,
        private readonly TransitionService $transitions,
        private readonly LineAllocator $allocator,
        private readonly SettlementAllocator $payments,
        private readonly WalletFunding $wallet,
        private readonly SlipStorage $slips,
        private readonly PromptDiscount $discounts,
    ) {}

    /**
     * Receipt-first submission (PLAN §1). The merchant has already
     * transferred; this call builds the batch, freezes its lines, stores the
     * slip on the private disk and records the claimed payment — all inside
     * ONE database transaction, so there is no instant at which a settlement
     * exists without its receipt, and nothing survives a failure halfway.
     *
     * The batch lands in payment_review: awaiting_payment is never observable
     * on this path, because the payment is recorded in the same breath as the
     * batch that owes it. An admin then reviews the slip — Match or Reject.
     *
     * The slip is inspected (magic bytes, size) BEFORE the transaction opens:
     * a forged or oversized upload must never even create a draft. The file
     * write cannot join the transaction, so a rollback deletes it explicitly.
     *
     * @param  list<int>|null  $transactionIds  null settles everything eligible
     *
     * @throws InvalidSlipException the upload is not a JPEG/PNG/WebP/PDF, or is too large
     * @throws NotEligibleForSettlementException nothing to settle, or nothing due
     * @throws DuplicateBankRefException this merchant already recorded that transfer
     */
    public function createAndSubmitWithReceipt(
        Merchant $merchant,
        MerchantUser $uploader,
        ?array $transactionIds,
        Laari $amount,
        ?string $bankRef,
        UploadedFile $slip,
        ?int $platformBankAccountId = null,
    ): Settlement {
        $inspection = $this->slips->inspect($slip);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($merchant, $uploader, $transactionIds, $amount, $bankRef, $slip, $inspection, $platformBankAccountId, &$storedPath): Settlement {
                $settlement = $this->submit($this->createDraft($merchant, $transactionIds));

                // Fully netted by §7 credits, or by them plus the PLAN §1
                // prompt-payment discount: no transfer is due, so no receipt
                // can be honest about one. submit() has already allocated and
                // settled such a batch — and the throw rolls the whole
                // attempt back, so nothing survives. The credit-netted batch
                // settles through createAndSettleFromWallet, which draws
                // nothing from the wallet either.
                //
                // Checked AFTER submit because the discount is evaluated
                // there, under the row lock: a draft can owe money and a
                // submitted batch owe nothing.
                if ($settlement->state !== SettlementState::AwaitingPayment) {
                    throw NotEligibleForSettlementException::nothingDue($merchant);
                }

                // WHERE they paid, recorded before the receipt so the two
                // are one act. Left alone when the caller names nothing, so
                // an older client that never offered the choice still
                // settles rather than failing on a field it does not know.
                if ($platformBankAccountId !== null) {
                    $settlement->forceFill(['platform_bank_account_id' => $platformBankAccountId])->save();
                }

                $storedPath = $this->slips->store($merchant, $settlement, $slip, $inspection);

                $this->payments->recordBankPayment(
                    $settlement,
                    $amount,
                    $bankRef,
                    ReceiptSlip::uploaded($storedPath, $inspection, $uploader),
                );

                return $settlement->refresh();
            });
        } catch (Throwable $exception) {
            $this->slips->delete($storedPath);

            throw $exception;
        }
    }

    /**
     * A FURTHER receipt against a batch the merchant already owns — the
     * second half of §7's partial-payment rule. Oldest-first allocation
     * leaves the uncovered lines Pending and frozen on the batch, so the
     * merchant cannot start a new settlement for them; without this they
     * would be stranded exactly the way the receipt-first decision set out to
     * prevent. It is also how a merchant pays an admin-built fallback batch
     * sitting in awaiting_payment.
     *
     * Same rules as the first receipt: inspected before anything is written,
     * stored privately, rolled back whole on failure.
     */
    public function addReceipt(
        Settlement $settlement,
        MerchantUser $uploader,
        Laari $amount,
        ?string $bankRef,
        UploadedFile $slip,
    ): SettlementPayment {
        $inspection = $this->slips->inspect($slip);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($settlement, $uploader, $amount, $bankRef, $slip, $inspection, &$storedPath): SettlementPayment {
                $storedPath = $this->slips->store($settlement->merchant, $settlement, $slip, $inspection);

                return $this->payments->recordBankPayment(
                    $settlement,
                    $amount,
                    $bankRef,
                    ReceiptSlip::uploaded($storedPath, $inspection, $uploader),
                );
            });
        } catch (Throwable $exception) {
            $this->slips->delete($storedPath);

            throw $exception;
        }
    }

    /**
     * The wallet counterpart of the receipt-first path (§7: "wallet
     * settlement runs the same path — only the funding source differs").
     * Build, freeze and fund from the balance in one transaction; no receipt
     * exists because no bank transfer happened — the money was already
     * evidenced by the top-up that put it in the wallet.
     *
     * A batch fully netted to zero by §7 credits settles inside submit()
     * without touching the wallet at all; that is the only route by which a
     * merchant settles a zero-due batch, and it draws no balance.
     *
     * @param  list<int>|null  $transactionIds  null settles everything eligible
     */
    public function createAndSettleFromWallet(Merchant $merchant, MerchantUser $actor, ?array $transactionIds): Settlement
    {
        return DB::transaction(function () use ($merchant, $actor, $transactionIds): Settlement {
            $settlement = $this->submit($this->createDraft($merchant, $transactionIds));

            if ($settlement->state === SettlementState::AwaitingPayment) {
                $this->wallet->settleFromWallet($settlement, $actor);
            }

            return $settlement->refresh();
        });
    }

    /**
     * The reject half of the admin receipt review (PLAN §1): the transfer
     * could not be verified, so the batch cancels, its lines are released —
     * the transactions return to payable_unfunded and become eligible for a
     * NEW settlement immediately — and every pending payment on it is marked
     * rejected with the reason, which the merchant sees on their batch.
     *
     * Only a payment_review batch with nothing matched can be rejected: once
     * a payment is matched, lines are allocated and customers' cashback is
     * confirmed — §13 says corrections after that are adjustments, never a
     * release.
     *
     * Lock order matches matchPayment (payments, then the settlement) so the
     * two admin actions serialise on the same rows in the same sequence
     * instead of deadlocking against each other — but the payment set that
     * DECIDES is re-read under the settlement lock, because FOR UPDATE locks
     * the rows it returns and does not block an INSERT.
     */
    public function reject(Settlement $settlement, AdminUser $actor, string $reason): Settlement
    {
        return DB::transaction(function () use ($settlement, $actor, $reason): Settlement {
            // Take the payment locks BEFORE the settlement row — matchPayment
            // locks in that order too, so the two admin actions queue behind
            // each other instead of deadlocking head-on. The rows themselves
            // are re-read below; this call is here for the lock order.
            $this->lockedPayments($settlement);

            $settlement = $this->locked($settlement);

            // Re-read UNDER the settlement lock. A receipt that committed
            // between the two reads is invisible to the first one, and
            // rejecting without it would strand a pending payment on a
            // cancelled batch: matchPayment refuses (cancelled is not
            // matchable), reject refuses (the batch has left payment_review),
            // and re-submitting the same transfer is refused as a duplicate
            // (the partial unique index exempts only rejected rows) — real
            // money in the platform's account with no in-app path to book it.
            // From here nothing more can arrive: recordBankPayment takes this
            // same settlement row lock before inserting, so it waits for this
            // commit and then finds a cancelled batch.
            $payments = $this->lockedPayments($settlement);

            if ($settlement->state !== SettlementState::PaymentReview) {
                throw InvalidSettlementStateException::forAction($settlement, 'rejecting the receipt', 'a batch in payment review');
            }

            if ($settlement->amount_received_laari !== 0 || $payments->contains(fn (SettlementPayment $payment): bool => $payment->state === 'matched')) {
                throw InvalidSettlementStateException::rejectAfterMatching($settlement);
            }

            $now = CarbonImmutable::now('UTC');

            foreach ($payments as $payment) {
                if ($payment->state !== 'pending') {
                    continue;
                }

                $payment->forceFill([
                    'state' => 'rejected',
                    'rejected_by' => $actor->id,
                    'rejected_at' => $now,
                    'rejection_reason' => $reason,
                ])->save();
            }

            $this->releaseLinesAndCancel($settlement);

            return $settlement;
        });
    }

    /**
     * Every payment row on the batch, locked and in id order.
     *
     * @return Collection<int, SettlementPayment>
     */
    private function lockedPayments(Settlement $settlement): Collection
    {
        return SettlementPayment::query()
            ->where('settlement_id', $settlement->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

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

            // PLAN §1: the prompt-payment discount is decided HERE — inside
            // the transaction, under the settlement lock, against the frozen
            // line set — and nowhere else. Whatever the preview told the
            // merchant is advisory; this is the answer that moves money.
            $this->applyPromptDiscount($settlement);

            // Defensive: removeLine on a draft carrying applied adjustments
            // can push the netted due below zero. A batch never leaves draft
            // owing the merchant money — add lines or drop the batch.
            if ($settlement->amount_due_laari < 0) {
                throw InvalidSettlementStateException::forAction($settlement, 'submit', 'a batch with a non-negative amount due');
            }

            // Fully netted by applied credits (and, at the margin, by the
            // prompt-payment discount): there is nothing to await — no
            // payment path accepts zero laari (bank payments must be
            // positive, a zero wallet movement is refused), so an
            // awaiting_payment batch owing 0 would strand its transactions
            // payable_unfunded past due_at and auto-suspend a merchant with
            // zero net debt. The credit IS the funding: every line allocates
            // now (confirming the customers' cashback through the state
            // machine) and the batch settles. The applied credits' journals
            // posted at application; the discount's posts here, with the
            // allocation it funds.
            if ($settlement->amount_due_laari === 0) {
                $now = CarbonImmutable::now('UTC');

                foreach (SettlementLines::inAllocationOrder($settlement) as $line) {
                    $this->allocator->allocate($line, Actor::system(), $now);
                }

                // The discount is covered funds like any other, and this is
                // the allocation instant for this batch — so its journal
                // posts here, once, exactly as it would through a payment.
                $this->postPromptDiscount($settlement);

                $settlement->forceFill(['state' => SettlementState::Settled])->save();

                return $settlement;
            }

            $settlement->forceFill(['state' => SettlementState::AwaitingPayment])->save();

            return $settlement;
        });
    }

    /**
     * Re-evaluates the PLAN §1 prompt-payment discount against the batch's
     * own (locked) transactions and writes the outcome onto the settlement:
     * the relief granted, the rate it was priced at, the machine reason — and
     * the reduced amount due.
     *
     * The rows are re-locked rather than trusted: on the receipt-first path
     * this transaction already holds them from createDraft, but submit() is
     * also reachable on its own (the admin fallback), and the eligibility
     * test reads state and clock_start_at off rows another request could
     * otherwise be moving underneath it.
     *
     * The grant is capped at what the batch still owes after §7 credits: a
     * discount must never hand money back, and a batch never leaves submit
     * owing the merchant.
     */
    private function applyPromptDiscount(Settlement $settlement): void
    {
        $transactionIds = $settlement->lines()->pluck('transaction_id')->all();

        $transactions = Transaction::query()
            ->whereIn('id', $transactionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $result = $this->discounts
            ->evaluate($settlement->merchant, $transactions, CarbonImmutable::now('UTC'))
            ->cappedTo($settlement->amount_due_laari);

        $settlement->forceFill([
            'discount_laari' => $result->discountLaari,
            'discount_rate_bp' => $result->storedRateBp(),
            'discount_reason' => $result->reason->value,
            'amount_due_laari' => $settlement->amount_due_laari - $result->discountLaari,
        ])->save();
    }

    /**
     * Posts a batch's stored discount as the §8 sales discount on our own
     * revenue, split back into its fee and GST legs from the settlement's own
     * integers. Callers own the "has this already posted?" question; the two
     * whole-batch paths (a zero-due submit, a wallet settlement) allocate
     * every line exactly once, so for them the answer is always no.
     */
    private function postPromptDiscount(Settlement $settlement): void
    {
        [$fee, $gst] = PromptDiscount::reliefLegs($settlement);

        if ($fee + $gst <= 0) {
            return;
        }

        $this->postings->promptPaymentDiscount($fee, $gst, referenceId: $settlement->id);
        $settlement->forceFill(['discount_posted_laari' => $fee + $gst])->save();
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
                throw InvalidSettlementStateException::forAction($settlement, 'cancel', 'a draft, or a batch awaiting payment that has received nothing');
            }

            // Defensive: awaiting_payment cannot hold payments (recording one
            // moves the batch to payment_review), but a recorded or matched
            // payment must always block cancellation.
            if ($settlement->amount_received_laari !== 0 || $settlement->payments()->exists()) {
                throw InvalidSettlementStateException::cancelWithMoneyReceived($settlement);
            }

            $this->releaseLinesAndCancel($settlement);

            return $settlement;
        });
    }

    /**
     * The release itself, shared by cancel() and the admin reject(): applied
     * §7 credits go back to pending, the lines are DELETED — nothing on them
     * was ever allocated, and settlement_lines.transaction_id is unique
     * across live batches, so deleting the row IS what returns the
     * transaction to payable_unfunded eligibility — the batch goes cancelled,
     * and any reversal memo the freeze was deferring is settled up now that
     * the freeze is gone.
     *
     * Callers own the state guard and the row lock.
     */
    private function releaseLinesAndCancel(Settlement $settlement): void
    {
        $this->unapplyAdjustments($settlement);

        $reversalMemos = $this->pendingReversalMemos($settlement);

        $settlement->lines()->delete();
        $settlement->forceFill(['state' => SettlementState::Cancelled])->save();

        $this->reverseReleasedMemoTransactions($reversalMemos);
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
        $pending = $this->applicablePendingAdjustments($settlement->merchant_id)->lockForUpdate()->get();

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
     * The §7 credit memos a NEW batch for this merchant would net in, in
     * strict FIFO order — the applicability rule of applyPendingAdjustments
     * expressed once so the merchant's pre-submit preview and the settlement
     * it eventually creates can never disagree about what is owed.
     *
     * @return Builder<Adjustment>
     */
    public function applicablePendingAdjustments(int $merchantId): Builder
    {
        return Adjustment::query()
            ->join('transactions', 'transactions.id', '=', 'adjustments.transaction_id')
            ->where('transactions.merchant_id', $merchantId)
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
            ->select('adjustments.*');
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
        DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('settlements-reference-'.$this->referenceYear())]);

        return $this->peekReference();
    }

    /**
     * The reference the next batch WOULD get, read without the advisory lock
     * — for the pre-submit preview only. Reservation-free by design: nothing
     * is claimed, so two merchants previewing at once see the same string and
     * the loser's batch simply gets the next one. The final reference is
     * assigned at submit, inside the lock (docs/openapi.yaml says so).
     */
    public function peekReference(): string
    {
        $prefix = sprintf('ST-%d-', $this->referenceYear());

        $latest = Settlement::query()->where('reference', 'like', $prefix.'%')->max('reference');
        $next = $latest === null ? 1 : (int) substr((string) $latest, strlen($prefix)) + 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function referenceYear(): int
    {
        return CarbonImmutable::now((string) config('app.business_timezone', 'Indian/Maldives'))->year;
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
