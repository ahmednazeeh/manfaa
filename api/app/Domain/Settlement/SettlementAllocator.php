<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\Actor;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Models\AdminUser;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bank-payment recording and the §7 allocation rule: oldest-first, whole
 * lines only, no tolerance band — a payment either covers lines fully or the
 * uncovered lines stay Pending. The single exception is the forgiveness
 * rule: a remaining unpaid batch balance under MVR 1 (< 100 laari) is
 * absorbed by the platform, never carried and never booked to bad debt.
 *
 * Ledger treatment of a matched payment, per component:
 *
 *   - the portion that fully covers lines  → bankSettlementReceived
 *     (DR Settlement Cash / CR Merchant Receivable)
 *   - a prior payment's remainder previously parked in the wallet, now
 *     consumed by newly covered lines → walletSettle
 *     (DR Merchant Wallet Balance / CR Merchant Receivable). The parked
 *     remainder is a SPENDABLE wallet credit (§7: applied to the next
 *     batch), so only what is actually still in the wallet is ever applied
 *     here — never more than the locked balance.
 *   - cash left over after allocation — overpayment or a partial payment's
 *     unallocated remainder — → wallet credit via walletTopUp
 *     (DR Settlement Cash / CR Merchant Wallet Balance). §7: overpayment is
 *     a merchant credit applied to the next batch, never a refund; and a
 *     partial's remainder is exactly the same kind of credit, so both take
 *     one auditable path and the receivable only ever moves by whole lines.
 *   - a forgiven shortfall (unpaid balance < 100 laari) →
 *     forgiveSettlementShortfall (DR Platform-Funded Rewards Expense /
 *     CR Merchant Receivable), so the receivable nets to zero and the
 *     customer's cashback confirms in full. The forgiven laari are never
 *     part of the bankSettlementReceived amount — cash only ever books what
 *     cash actually covered.
 */
final class SettlementAllocator
{
    /**
     * §7 forgiveness rule: a remaining unpaid batch balance strictly under
     * MVR 1 is forgiven. Exactly 100 laari is NOT forgiven.
     */
    public const int FORGIVENESS_THRESHOLD_LAARI = 100;

    public function __construct(
        private readonly Postings $postings,
        private readonly WalletFunding $wallet,
        private readonly LineAllocator $allocator,
    ) {}

    /**
     * Registers a claimed bank transfer against the batch. The payment sits
     * pending until an admin matches it; the settlement moves to
     * payment_review so the matching queue picks it up.
     *
     * Idempotent per transfer: the unique (settlement_id, bank_ref) index is
     * the authority on duplicates — recording the same reference twice rolls
     * the whole attempt back and surfaces as DuplicateBankRefException, so
     * one real transfer can never book cash twice.
     */
    public function recordBankPayment(Settlement $settlement, Laari $amount, string $bankRef, ?string $slipPath = null): SettlementPayment
    {
        if ($amount->value() <= 0) {
            throw new InvalidArgumentException('A settlement payment must be a positive amount.');
        }

        try {
            return $this->storeBankPayment($settlement, $amount, $bankRef, $slipPath);
        } catch (UniqueConstraintViolationException) {
            throw DuplicateBankRefException::forSettlement($settlement, $bankRef);
        }
    }

    private function storeBankPayment(Settlement $settlement, Laari $amount, string $bankRef, ?string $slipPath): SettlementPayment
    {
        return DB::transaction(function () use ($settlement, $amount, $bankRef, $slipPath): SettlementPayment {
            $settlement = $this->locked($settlement);

            $payable = [SettlementState::AwaitingPayment, SettlementState::PaymentReview, SettlementState::PartiallySettled];

            if (! in_array($settlement->state, $payable, true)) {
                throw InvalidSettlementStateException::forAction($settlement, 'recording a payment', 'a submitted, unsettled batch');
            }

            $payment = $settlement->payments()->create([
                'amount_laari' => $amount->value(),
                'currency' => 'MVR',
                'method' => 'bank',
                'bank_ref' => $bankRef,
                'slip_path' => $slipPath,
                'state' => 'pending',
            ]);

            if ($settlement->state !== SettlementState::PaymentReview) {
                $settlement->forceFill(['state' => SettlementState::PaymentReview])->save();
            }

            return $payment;
        });
    }

    /**
     * Matches a pending payment and allocates the newly available funds
     * oldest-first (§7), all inside one DB transaction. Whole lines only —
     * a line is taken when the available funds cover it in full, and each
     * newly covered line's transaction is confirmed through the state
     * machine with confirmed_at stamped. After the walk, an unpaid balance
     * under 100 laari triggers the forgiveness rule: every remaining line
     * allocates too and the platform books the gap. The batch lands on
     * settled only when every line is allocated; partially_settled otherwise.
     *
     * Available funds are this payment's cash, plus any §7 adjustment credit
     * netted into the batch at draft time (already posted at application —
     * counted for coverage, never posted again), plus whatever of a prior
     * payment's parked remainder is still sitting in the wallet. The parked
     * remainder is a spendable §7 credit, so if the merchant already applied
     * it to another batch it is NOT counted again here — the wallet is never
     * driven below zero on behalf of a match.
     *
     * A pending payment is matchable whenever it exists: recording one puts
     * the batch in payment_review, and a later match may find the batch
     * already partially_settled or even settled (a second transfer recorded
     * before the first was matched, or a duplicate payment on a settled
     * batch). Post-settlement cash allocates nothing and becomes a pure §7
     * wallet credit — real money is never left unbookable.
     */
    public function matchPayment(SettlementPayment $payment, AdminUser $actor): Settlement
    {
        return DB::transaction(function () use ($payment, $actor): Settlement {
            $payment = SettlementPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($payment->state !== 'pending') {
                throw InvalidSettlementStateException::paymentNotPending($payment);
            }

            $settlement = Settlement::query()->whereKey($payment->settlement_id)->lockForUpdate()->firstOrFail();

            $matchable = [SettlementState::PaymentReview, SettlementState::PartiallySettled, SettlementState::Settled];

            if (! in_array($settlement->state, $matchable, true)) {
                throw InvalidSettlementStateException::forAction($settlement, 'matching a payment', 'a submitted batch holding the payment');
            }

            $now = CarbonImmutable::now('UTC');

            $payment->forceFill([
                'state' => 'matched',
                'matched_by' => $actor->id,
                'matched_at' => $now,
            ])->save();

            $receivedBefore = $settlement->amount_received_laari;
            $received = $receivedBefore + $payment->amount_laari;
            $due = $settlement->amount_due_laari;

            $lines = SettlementLines::inAllocationOrder($settlement);

            $lineTotal = 0;
            $previouslyAllocated = 0;

            foreach ($lines as $line) {
                $lineTotal += SettlementLines::due($line);

                if ($line->allocated_at !== null) {
                    $previouslyAllocated += SettlementLines::due($line);
                }
            }

            // §7 adjustment credits netted into this batch at draft time
            // (amount_due = line total − applied credits). Their
            // reverseAccrual already posted at application, so here they are
            // PRE-POSTED funding: consumed before any cash, never re-posted,
            // and emphatically never "forgiven" — forgiveness is reserved for
            // real sub-MVR-1 shortfalls.
            $adjustmentCredit = max(0, $lineTotal - $due);
            $adjustmentAvailable = max(0, $adjustmentCredit - $previouslyAllocated);

            // What this match can actually fund: the credit remainder, the
            // payment's cash, plus the portion of any earlier unallocated cash
            // remainder that is BOTH still owed to this batch and still
            // present in the wallet. Prior allocations consumed the credit
            // first, so the cash they consumed is what the credit left over.
            $priorCashConsumed = $previouslyAllocated - min($adjustmentCredit, $previouslyAllocated);
            $priorUnallocated = max(0, $receivedBefore - $priorCashConsumed);
            $walletAvailable = min($priorUnallocated, $this->wallet->lockedBalance($settlement->merchant));
            $funds = $payment->amount_laari + $walletAvailable + $adjustmentAvailable;
            $remainingDue = $lineTotal - $previouslyAllocated;

            // The forgiveness rule (§7): strictly under 100 laari short of the
            // whole batch, every remaining line allocates and the platform
            // absorbs the gap. At exactly 100 laari nothing is forgiven.
            $unpaid = max(0, $remainingDue - $funds);
            $forgiving = $unpaid > 0 && $unpaid < self::FORGIVENESS_THRESHOLD_LAARI;
            $coversEverything = $funds >= $remainingDue || $forgiving;

            // Oldest-first walk over the not-yet-allocated lines. A line is
            // taken only when the available funds cover it in full — unless
            // forgiveness covers the whole batch anyway.
            $newlyAllocated = [];
            $allocatedNow = 0;

            foreach ($lines as $line) {
                if ($line->allocated_at !== null) {
                    continue;
                }

                $lineDue = SettlementLines::due($line);

                if (! $coversEverything && $allocatedNow + $lineDue > $funds) {
                    break;
                }

                $newlyAllocated[] = $line;
                $allocatedNow += $lineDue;
            }

            foreach ($newlyAllocated as $line) {
                $this->allocator->allocate($line, Actor::admin($actor->id), $now);
            }

            // Fund the newly allocated total: the pre-posted adjustment credit
            // first (no posting — its journal exists since application), then
            // this payment's cash, then the still-present wallet remainder,
            // and — only when forgiving — the platform-absorbed shortfall.
            $adjustmentPortion = min($adjustmentAvailable, $allocatedNow);
            $cashPortion = min($payment->amount_laari, $allocatedNow - $adjustmentPortion);
            $walletPortion = min($walletAvailable, $allocatedNow - $adjustmentPortion - $cashPortion);
            $forgivenShortfall = $allocatedNow - $adjustmentPortion - $cashPortion - $walletPortion;
            $remainder = $payment->amount_laari - $cashPortion;

            if ($cashPortion > 0) {
                $this->postings->bankSettlementReceived($cashPortion, referenceId: $settlement->id);
            }

            if ($walletPortion > 0) {
                $this->wallet->debit(
                    $settlement->merchant,
                    $walletPortion,
                    'settlement',
                    'settlement',
                    $settlement->id,
                    sprintf('Wallet credit applied to %s', $settlement->reference),
                );
                $this->postings->walletSettle($walletPortion, referenceId: $settlement->id);
            }

            if ($forgivenShortfall > 0) {
                $this->postings->forgiveSettlementShortfall($forgivenShortfall, referenceId: $settlement->id);
            }

            if ($remainder > 0) {
                $movement = $this->wallet->credit(
                    $settlement->merchant,
                    $remainder,
                    'settlement_credit',
                    'settlement',
                    $settlement->id,
                    sprintf(
                        $coversEverything ? 'Overpayment on %s' : 'Unallocated remainder on %s',
                        $settlement->reference,
                    ),
                );
                $this->postings->walletTopUp($remainder, referenceId: $movement->id);
            }

            $settlement->forceFill([
                'amount_received_laari' => $received,
                // Settled means every LINE is allocated; on an adjustment-
                // netted batch the line total exceeds the netted amount_due,
                // so completeness is measured against the lines.
                'state' => $previouslyAllocated + $allocatedNow === $lineTotal
                    ? SettlementState::Settled
                    : SettlementState::PartiallySettled,
            ])->save();

            return $settlement;
        });
    }

    private function locked(Settlement $settlement): Settlement
    {
        return Settlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
    }
}
