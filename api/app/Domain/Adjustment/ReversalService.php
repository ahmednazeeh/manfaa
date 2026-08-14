<?php

declare(strict_types=1);

namespace App\Domain\Adjustment;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\FutureDatedTransactionException;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\Postings;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Models\Adjustment;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The §9.2 reversal decision tree, §6/§7 made concrete:
 *
 *  - pre-confirmation and not locked in a live settlement → reverse in
 *    place: state → reversed through TransitionService, and the accrual is
 *    mirrored with Postings::reverseAccrual using the STORED integers.
 *  - locked in a non-draft settlement, or already confirmed or PAID
 *    (docs/openapi.yaml: cause already_confirmed covers both) → the
 *    transaction is untouched; a pending credit Adjustment is created
 *    instead. Creation is a MEMO: no ledger entry is posted here. The
 *    adjustment's credit journal posts exactly once, at the moment it is
 *    APPLIED to a settlement draft (SettlementBuilder::createDraft) —
 *    posting at creation would credit the receivable before the batch that
 *    nets it exists, and a second posting at application would
 *    double-count.
 *  - reversed / written_off (the terminal states) →
 *    InvalidReversalStateException (409 invalid_state).
 *
 * A transaction sitting in a DRAFT settlement is still reversible (§7 locks
 * only non-draft batches); its line is removed from the draft first so the
 * batch totals stay a sum of live lines.
 *
 * Concurrency: every decision above is made INSIDE one DB transaction,
 * under the transaction row lock, with the row of any live settlement
 * holding the line locked too. SettlementBuilder::submit locks the
 * settlement row, so a reversal racing a draft submit serialises on it:
 * whichever commits first decides — the loser sees either a batch that has
 * left draft (→ adjustment, the §7 distinct outcome) or a draft whose line
 * is already gone. Checked any earlier, a reversal could reverse a
 * transaction whose line had just frozen inside an awaiting_payment batch,
 * leaving the batch billing a reversed sale that no payment could ever
 * match.
 */
final readonly class ReversalService
{
    /** Same clock-skew allowance as creation (§9.2: "same rules"). */
    private const int FUTURE_SKEW_MINUTES = 5;

    public function __construct(
        private TransitionService $transitions,
        private Postings $postings,
        private SettlementBuilder $builder,
    ) {}

    public function reverse(
        Transaction $transaction,
        Actor $actor,
        string $reason,
        CarbonImmutable $occurredAt,
    ): ReversalOutcome {
        $occurredAt = $occurredAt->utc();

        if ($occurredAt->isAfter(CarbonImmutable::now('UTC')->addMinutes(self::FUTURE_SKEW_MINUTES))) {
            throw FutureDatedTransactionException::at($occurredAt);
        }

        return DB::transaction(function () use ($transaction, $actor, $reason, $occurredAt): ReversalOutcome {
            /** @var Transaction $transaction */
            $transaction = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($transaction->state, [TransactionState::Reversed, TransactionState::WrittenOff], true)) {
                throw InvalidReversalStateException::for($transaction);
            }

            // §6: corrections after confirmation are adjustments — and the
            // published contract routes PAID the same way (a post-payout
            // refund offsets the customer's future earnings, §11; the
            // merchant's credit must not vanish into a 409).
            if (in_array($transaction->state, [TransactionState::Confirmed, TransactionState::Paid], true)) {
                return $this->createAdjustment($transaction, $reason, ReversalOutcome::CAUSE_ALREADY_CONFIRMED, $occurredAt);
            }

            // Lock the row of any live settlement holding this line, so a
            // concurrent submit() of that batch serialises with us here.
            $settlement = Settlement::query()
                ->join('settlement_lines', 'settlement_lines.settlement_id', '=', 'settlements.id')
                ->where('settlement_lines.transaction_id', $transaction->id)
                ->where('settlements.state', '!=', SettlementState::Cancelled->value)
                ->select('settlements.*')
                ->lockForUpdate()
                ->first();

            // §7 locked batches: left draft → the distinct adjustment outcome.
            if ($settlement !== null && $settlement->state !== SettlementState::Draft) {
                return $this->createAdjustment($transaction, $reason, ReversalOutcome::CAUSE_LOCKED_IN_SETTLEMENT, $occurredAt);
            }

            // §7 locks only non-draft settlements: a draft still holding this
            // transaction gives its line back, so the draft's totals never
            // include a reversed sale.
            if ($settlement !== null) {
                $line = SettlementLine::query()
                    ->where('settlement_id', $settlement->id)
                    ->where('transaction_id', $transaction->id)
                    ->first();

                if ($line !== null) {
                    $this->builder->removeLine($settlement, $line);
                }
            }

            // Belt-and-braces: an in-place reversal fully mirrors the
            // accrual, so any pending memo for this sale (there should be
            // none — cancellation heals them) must be retired with it, or
            // its credit would net a future batch on top of this mirror.
            Adjustment::query()
                ->where('transaction_id', $transaction->id)
                ->where('state', 'pending')
                ->update(['state' => 'voided', 'voided_at' => CarbonImmutable::now('UTC')]);

            $this->transitions->reverse(
                $transaction,
                $actor,
                $reason,
                ['reversal_occurred_at' => $occurredAt->toIso8601String()],
            );

            // Mirror the accrual with the STORED integers — never recompute
            // from a rate that may have changed since. Zero-accrual rows
            // (nothing was ever posted) have nothing to mirror.
            $accrued = $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;

            if ($accrued > 0) {
                $this->postings->reverseAccrual(
                    $transaction->cashback_laari,
                    $transaction->fee_laari,
                    $transaction->fee_gst_laari,
                    referenceId: $transaction->id,
                );
            }

            return ReversalOutcome::reversed($transaction->refresh());
        });
    }

    /**
     * The adjustment is a snapshot of the transaction's STORED component
     * integers, negated — cashback, fee and GST kept separately so the
     * eventual credit journal posts the exact mirror of the original
     * accrual. amount_laari is their (negative) sum.
     *
     * Idempotent per transaction: the row lock (already held by reverse())
     * serialises concurrent reversals, and an adjustment that already exists
     * (any state) is returned instead of creating a second credit for the
     * same sale.
     */
    private function createAdjustment(
        Transaction $transaction,
        string $reason,
        string $cause,
        CarbonImmutable $occurredAt,
    ): ReversalOutcome {
        $existing = Adjustment::query()
            ->where('transaction_id', $transaction->id)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return ReversalOutcome::adjustmentCreated($transaction, $existing, $cause);
        }

        $adjustment = Adjustment::query()->create([
            'transaction_id' => $transaction->id,
            'amount_laari' => -($transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari),
            'cashback_laari' => -$transaction->cashback_laari,
            'fee_laari' => -$transaction->fee_laari,
            'fee_gst_laari' => -$transaction->fee_gst_laari,
            'currency' => $transaction->currency,
            'reason_code' => $reason,
            'note' => sprintf('Vendor reversal (%s); reversal occurred_at %s', $cause, $occurredAt->toIso8601String()),
            'state' => 'pending',
        ]);

        return ReversalOutcome::adjustmentCreated($transaction, $adjustment, $cause);
    }
}
