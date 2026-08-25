<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\Actor;
use App\Domain\Ledger\Postings;
use App\Domain\MerchantAccess\Permission;
use App\Domain\Money\Laari;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Transfers\BankCreditClaim;
use App\Jobs\PollSettlementPayment;
use App\Jobs\ReadSettlementReceipt;
use App\Models\AdminUser;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
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
 *   - the PLAN §1 prompt-payment discount the batch was granted at submit →
 *     promptPaymentDiscount (DR Platform Fee Revenue / CR Merchant
 *     Receivable). Covered funds like an applied credit, so a merchant who
 *     transfers the discounted amount settles every line — but posted at
 *     ALLOCATION, once, because a rejected receipt must leave no discount
 *     journal behind, and only on the match that COMPLETES the batch,
 *     because the relief was granted for clearing the board.
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
        private readonly NotificationService $notifications,
        private readonly BankCreditClaim $claims,
    ) {}

    /**
     * Registers a claimed bank transfer against the batch. The payment sits
     * pending until an admin matches it; the settlement moves to
     * payment_review so the matching queue picks it up.
     *
     * Idempotent per transfer WHEN A REFERENCE IS GIVEN: the unique
     * (settlement_id, bank_ref) index and the partial unique
     * (merchant_id, bank_ref) index (non-rejected rows) are together the
     * authority on duplicates — recording the same reference twice, on this
     * batch or on a second batch the merchant just created, rolls the whole
     * attempt back and surfaces as DuplicateBankRefException, so one real
     * transfer can never book cash twice.
     *
     * The reference is OPTIONAL, because a merchant standing at the slip
     * upload frequently does not have it and the slip image carries it
     * anyway. Postgres treats NULLs as distinct and the merchant-scoped
     * index is explicitly `WHERE bank_ref IS NOT NULL`, so unreferenced
     * payments simply do not participate in that check — which means the
     * ADMIN MATCHING QUEUE is the only thing standing between one real
     * transfer and two booked payments. That is the trade: fewer merchants
     * blocked at upload, more resting on the reviewer comparing the slip to
     * the statement.
     */
    public function recordBankPayment(Settlement $settlement, Laari $amount, ?string $bankRef, ?ReceiptSlip $slip = null): SettlementPayment
    {
        if ($amount->value() <= 0) {
            throw new InvalidArgumentException('A settlement payment must be a positive amount.');
        }

        // One transfer funds one thing: a reference this merchant already
        // claimed as a WALLET TOP-UP (pending or matched) cannot also pay a
        // batch. The per-table indexes cannot see across tables; this can.
        if ($bankRef !== null && $this->heldByTopUp($settlement, $bankRef)) {
            throw DuplicateBankRefException::forSettlementHeldByTopUp($settlement, $bankRef);
        }

        try {
            return $this->storeBankPayment($settlement, $amount, $bankRef, $slip);
        } catch (UniqueConstraintViolationException) {
            throw DuplicateBankRefException::forSettlement($settlement, $bankRef);
        }
    }

    private function heldByTopUp(Settlement $settlement, string $bankRef): bool
    {
        return WalletTopUp::query()
            ->where('merchant_id', $settlement->merchant_id)
            ->where('bank_ref', $bankRef)
            ->where('state', '!=', 'rejected')
            ->exists();
    }

    private function storeBankPayment(Settlement $settlement, Laari $amount, ?string $bankRef, ?ReceiptSlip $slip): SettlementPayment
    {
        return DB::transaction(function () use ($settlement, $amount, $bankRef, $slip): SettlementPayment {
            $settlement = $this->locked($settlement);

            $payable = [SettlementState::AwaitingPayment, SettlementState::PaymentReview, SettlementState::PartiallySettled];

            if (! in_array($settlement->state, $payable, true)) {
                throw InvalidSettlementStateException::forAction($settlement, 'recording a payment', 'a submitted, unsettled batch');
            }

            $payment = $settlement->payments()->create([
                'merchant_id' => $settlement->merchant_id,
                'amount_laari' => $amount->value(),
                'currency' => 'MVR',
                'method' => 'bank',
                'bank_ref' => $bankRef,
                'slip_path' => $slip?->path,
                'slip_mime' => $slip?->mime,
                'slip_size_bytes' => $slip?->sizeBytes,
                'uploaded_by' => $slip?->uploadedBy,
                'state' => 'pending',
            ]);

            if ($settlement->state !== SettlementState::PaymentReview) {
                $settlement->forceFill(['state' => SettlementState::PaymentReview])->save();
            }

            $this->watchTheBank($payment);

            return $payment;
        });
    }

    /**
     * Open the bank-watching window on a freshly recorded payment.
     *
     * The clock starts NOW and is stored on the row, so a worker restarted
     * mid-window resumes where it actually is rather than beginning the
     * fifteen minutes again. Dispatched after commit, because a job that
     * reads the row before its transaction lands finds nothing.
     *
     * THE WINDOW IS THE RECORD THAT A WATCH REALLY STARTED. It is written
     * only when a poll is actually dispatched: an unstamped row is one
     * nobody ever looked at, and {@see BankWatch} says exactly that. Stamping
     * it while the switch is off left a live-looking fifteen minutes with no
     * job behind it — and the moment an admin turned the switch on, the
     * merchant's screen drew a progress bar over nothing.
     */
    private function watchTheBank(SettlementPayment $payment): void
    {
        $settings = TransferSetting::current();

        if (! $settings->auto_verify_enabled) {
            // The flag is the point: this ships dark until the tunnel is up.
            // No job, and therefore no window on the row.
            return;
        }

        $now = CarbonImmutable::now();

        $payment->forceFill([
            'poll_started_at' => $now,
            'poll_until' => $now->addMinutes((int) $settings->verify_window_minutes),
            'poll_attempts' => 0,
        ])->save();

        DB::afterCommit(function () use ($payment): void {
            // The slip first: the poll asks whether the bank's payer and
            // reference appear on it, and it retries for the whole window,
            // so text landing a few seconds later is still in time.
            if ($payment->slip_path !== null) {
                ReadSettlementReceipt::dispatch((int) $payment->getKey());
            }

            PollSettlementPayment::dispatch((int) $payment->getKey());
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
     * "This payment's cash" means WHAT THE BANK CREDITED (owner, 2026-08-25):
     * `received_laari`, stamped by the verifier off the matched statement
     * row, falling back to the merchant's claimed `amount_laari` only where
     * no bank figure exists. A merchant who typed MVR 20 and sent MVR 10
     * funds MVR 10 worth of lines and still owes the rest; one who typed
     * MVR 10 and sent MVR 20 settles and parks the remainder in the wallet.
     * Neither is a new branch — both are the branches below, reached by a
     * figure that is now true.
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
    /**
     * @param  AdminUser|null  $actor  Null for an automatic match against the
     *                                 bank's own history. `matched_by` then stays null on purpose — no
     *                                 person decided it, and filing one would put a name on a machine's
     *                                 call. `auto_matched` on the row is what records who did.
     * @param  Laari|null  $statementAmount  WHAT THE STATEMENT SAYS ARRIVED, stated by
     *                                       the reviewer on a hand match (owner, 2026-08-25). There is no bank
     *                                       row on that path, so nothing here can discover the figure; the
     *                                       merchant's `amount_laari` is a CLAIM, and spending it unseen is how
     *                                       a typo funds a batch. Null keeps the row's existing
     *                                       `received_laari` — the verifier's own stamp on an automatic match,
     *                                       and the claim as the fallback where nobody ever stated a figure,
     *                                       which is exactly what every row here spent before this existed.
     * @param  string|null  $statementRef  THE REFERENCE THE REVIEWER READ off the same
     *                                     statement line (verifier round, 2026-08-25). Without it a payment the
     *                                     merchant left blank was matched with nothing written to `bank_ref` or
     *                                     `matched_trx_refs` at all, so {@see BankCreditClaim} reported the
     *                                     credit unspent forever and a later top-up or order could take it a
     *                                     second time. Optional, not required: an admin also records and
     *                                     reconciles payments here for merchants who quoted no reference, and
     *                                     refusing those would block correct work.
     *
     * @throws DuplicateBankRefException an order, another payment, a top-up or a wallet movement already spent this credit
     */
    public function matchPayment(SettlementPayment $payment, ?AdminUser $actor, ?Laari $statementAmount = null, ?string $statementRef = null): Settlement
    {
        if ($statementAmount !== null && $statementAmount->value() <= 0) {
            throw new InvalidArgumentException('A matched payment must have received a positive amount.');
        }

        $statementRef = trim((string) $statementRef);
        $statementRef = $statementRef === '' ? null : $statementRef;

        try {
            return $this->match($payment, $actor, $statementAmount, $statementRef);
        } catch (UniqueConstraintViolationException) {
            // The reference the reviewer stated is already on another payment
            // of this merchant's — the partial unique index is the authority,
            // and this is its message.
            throw DuplicateBankRefException::forSettlementPaymentSpent($payment, (string) $statementRef);
        }
    }

    private function match(SettlementPayment $payment, ?AdminUser $actor, ?Laari $statementAmount, ?string $statementRef): Settlement
    {
        return DB::transaction(function () use ($payment, $actor, $statementAmount, $statementRef): Settlement {
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

            $matched = [
                'state' => 'matched',
                'matched_by' => $actor?->id,
                'matched_at' => $now,
            ];

            // A person matched it, so no bank row was read and nothing
            // wrote matched_trx_*. Record the reference as the credit's
            // name, so BankCreditClaim sees a hand-matched payment as spent
            // exactly like an automatic one — otherwise the same transfer
            // could go on to fund a wallet top-up.
            //
            // The merchant's own reference where they typed one, and the one
            // the reviewer read off the statement otherwise or as well: a
            // payment matched with NO name at all leaves the credit
            // permanently unspent as far as every other path can tell.
            $bankRef = trim((string) $payment->bank_ref);
            $references = array_values(array_unique(array_filter([
                $bankRef,
                (string) $statementRef,
            ], static fn (string $value): bool => $value !== '')));

            if ($actor !== null && $references !== []) {
                // Serialise on the credit itself, then ask whether it is
                // already spent — the same two steps the verifiers take
                // (SettlementPaymentVerifier::match), which this path did not
                // take at all: an admin could hand-match a credit a top-up or
                // an order had already spent, and it would credit twice.
                $this->claims->lockReferences($references);

                if ($this->claims->spent($references, exceptPayment: (int) $payment->getKey())) {
                    throw DuplicateBankRefException::forSettlementPaymentSpent($payment, $references[0]);
                }

                if ($payment->matched_trx_id === null && empty($payment->matched_trx_refs)) {
                    $matched['matched_trx_refs'] = $references;
                }

                // The merchant left it blank and the reviewer supplied it:
                // record it where every other reader of this row looks.
                if ($bankRef === '' && $statementRef !== null) {
                    $matched['bank_ref'] = $statementRef;
                }
            }

            // The reviewer read a figure off the statement, so record it as
            // the FACT before anything spends it. The CLAIM (`amount_laari`)
            // is never rewritten — the two sit side by side on the row, which
            // is the whole point of the pair.
            if ($statementAmount !== null) {
                $matched['received_laari'] = $statementAmount->value();
            }

            $payment->forceFill($matched)->save();

            // WHAT ACTUALLY ARRIVED (owner, 2026-08-25) — the bank's own
            // figure where the verifier found the transfer and stamped
            // `received_laari`, and the merchant's claim only where nobody
            // has a bank figure (an admin reconciling off a statement), which
            // is what every row here spent before this existed.
            //
            // Read ONCE, into the name the whole stack below spends. Nothing
            // else changes: a payment smaller than the batch already flows
            // down the partial path (whole lines only, remainder still owed)
            // and a larger one down the wallet-remainder path at the bottom.
            // Both are exercised by a merchant's typo now rather than only by
            // a merchant who meant it.
            $paid = $payment->creditedLaari();

            $receivedBefore = $settlement->amount_received_laari;
            $received = $receivedBefore + $paid;
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

            // The PLAN §1 prompt-payment discount decided at submit. Covered
            // funds exactly like an applied credit — a merchant who transfers
            // the DISCOUNTED amount settles every line, with no residue and
            // no forgiveness — but posted HERE rather than at submit: a batch
            // whose receipt is rejected never allocates and must leave no
            // discount journal behind.
            $discount = (int) $settlement->discount_laari;

            // §7 adjustment credits netted into this batch at draft time
            // (amount_due = line total − applied credits − discount). Their
            // reverseAccrual already posted at application, so here they are
            // PRE-POSTED funding: consumed before any cash, never re-posted,
            // and emphatically never "forgiven" — forgiveness is reserved for
            // real sub-MVR-1 shortfalls.
            $adjustmentCredit = max(0, $lineTotal - $due - $discount);

            // What makes the discount posting idempotent is the row's own
            // record of how much of it has reached the ledger — a re-match,
            // or a partial-then-complete sequence, can only ever post the
            // remainder, and the batch's completing allocation posts the last
            // laari of it. Prior allocations ate the adjustment credit first;
            // whatever they allocated beyond the credit and the posted
            // discount was funded by cash, whichever order it was spent in.
            $adjustmentAvailable = max(0, $adjustmentCredit - $previouslyAllocated);
            $discountPosted = (int) $settlement->discount_posted_laari;
            $discountAvailable = $discount - $discountPosted;

            // What this match can actually fund: the pre-posted credit
            // remainder, the payment's cash, plus the portion of any earlier
            // unallocated cash remainder that is BOTH still owed to this
            // batch and still present in the wallet. The discount is counted
            // separately below — it is not funding of the same kind.
            $priorCashConsumed = max(0, $previouslyAllocated - $adjustmentCredit - $discountPosted);
            $priorUnallocated = max(0, $receivedBefore - $priorCashConsumed);
            $walletAvailable = min($priorUnallocated, $this->wallet->lockedBalance($settlement->merchant));
            $merchantFunds = $paid + $walletAvailable + $adjustmentAvailable;
            $funds = $merchantFunds + $discountAvailable;
            $remainingDue = $lineTotal - $previouslyAllocated;

            // The forgiveness rule (§7): strictly under 100 laari short of the
            // whole batch, every remaining line allocates and the platform
            // absorbs the gap. At exactly 100 laari nothing is forgiven.
            $unpaid = max(0, $remainingDue - $funds);
            $forgiving = $unpaid > 0 && $unpaid < self::FORGIVENESS_THRESHOLD_LAARI;
            $coversEverything = $funds >= $remainingDue || $forgiving;

            // The discount was granted for CLEARING THE BOARD (PLAN §1), so
            // it funds only a match that finishes the batch. On a match that
            // leaves lines behind it is withheld — not forfeited: it stays on
            // the row, unposted, and funds the completion whenever it comes.
            // Without this, a merchant who paid a fraction of the batch
            // consumed the WHOLE relief first (it was spent ahead of their
            // own cash) and could then walk away from the rest, leaving the
            // platform having discounted a settlement that never happened.
            // The exact-payment arithmetic is untouched: a merchant who
            // transfers the discounted amount covers everything in one match,
            // and the discount is spent there exactly as before.
            $discountSpendable = $coversEverything ? $discountAvailable : 0;

            // Oldest-first walk over the not-yet-allocated lines. A line is
            // taken only when the available funds cover it in full — unless
            // forgiveness covers the whole batch anyway. A partial match
            // walks on the merchant's own funds alone, for the same reason.
            $newlyAllocated = [];
            $allocatedNow = 0;

            foreach ($lines as $line) {
                if ($line->allocated_at !== null) {
                    continue;
                }

                $lineDue = SettlementLines::due($line);

                if (! $coversEverything && $allocatedNow + $lineDue > $merchantFunds) {
                    break;
                }

                $newlyAllocated[] = $line;
                $allocatedNow += $lineDue;
            }

            foreach ($newlyAllocated as $line) {
                // An automatic match has no admin behind it. `matched_by`
                // above already stays null for that case; this line did not,
                // and read `->id` straight off the null — so the whole
                // auto-match path died the moment it tried to allocate.
                // `system` is the actor the ledger has for exactly this.
                $this->allocator->allocate(
                    $line,
                    $actor !== null ? Actor::admin($actor->id) : Actor::system(),
                    $now,
                );
            }

            // Fund the newly allocated total: the pre-posted adjustment credit
            // first (no posting — its journal exists since application), then
            // the prompt-payment discount, then this payment's cash, then the
            // still-present wallet remainder, and — only when forgiving — the
            // platform-absorbed shortfall.
            $adjustmentPortion = min($adjustmentAvailable, $allocatedNow);
            $discountPortion = min($discountSpendable, $allocatedNow - $adjustmentPortion);
            $cashPortion = min($paid, $allocatedNow - $adjustmentPortion - $discountPortion);
            $walletPortion = min($walletAvailable, $allocatedNow - $adjustmentPortion - $discountPortion - $cashPortion);
            $forgivenShortfall = $allocatedNow - $adjustmentPortion - $discountPortion - $cashPortion - $walletPortion;
            $remainder = $paid - $cashPortion;

            if ($discountPortion > 0) {
                // Split back into the fee-revenue and GST legs from the
                // batch's own integers, fee leg first — the same order the
                // grant itself was capped in, so a discount consumed across
                // two matches posts each leg exactly once in total.
                [$feeLeg] = PromptDiscount::reliefLegs($settlement);
                $feeNow = min(max(0, $feeLeg - $discountPosted), $discountPortion);

                $this->postings->promptPaymentDiscount($feeNow, $discountPortion - $feeNow, referenceId: $settlement->id);
            }

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

            // Captured BEFORE the write: the gate below must fire on a
            // TRANSITION into settled, not on the state afterwards. Two
            // payments recorded while the batch sits in payment_review are
            // matched in sequence, and on the second every line is already
            // allocated — so the row is re-written `settled` and, tested
            // after the fact, would announce "paid off, thank you" a second
            // time while saying nothing about the overpayment now parked in
            // the wallet.
            $wasSettled = $settlement->state === SettlementState::Settled;

            $settlement->forceFill([
                'amount_received_laari' => $received,
                // Written with the journal it counts, in the same
                // transaction: the row can never claim a posting that rolled
                // back, nor forget one that committed.
                'discount_posted_laari' => $discountPosted + $discountPortion,
                // Settled means every LINE is allocated; on an adjustment-
                // netted batch the line total exceeds the netted amount_due,
                // so completeness is measured against the lines.
                'state' => $previouslyAllocated + $allocatedNow === $lineTotal
                    ? SettlementState::Settled
                    : SettlementState::PartiallySettled,
            ])->save();

            // A transfer that was found and banked and did NOT close the
            // batch (verifier round, 2026-08-25). Announcing "accepted" here
            // would read as "we are done" when they are not — but saying
            // NOTHING is the worse of the two, and was the behaviour: once
            // the bank's figure funds the allocation rather than the typed
            // claim, a merchant who believes they paid the batch in full and
            // sent less gets no push, no SMS and no in-app line, while both
            // merchant surfaces promise a message the moment their transfer
            // is matched. So they are told what arrived and what is left.
            //
            // Sent from inside this transaction, like its settled sibling: a
            // message about an allocation that rolled back is a lie.
            if ($settlement->state === SettlementState::PartiallySettled) {
                $this->notifications->sendToMerchantStaff(
                    NotificationTemplateKey::SettlementPartiallyPaid,
                    $settlement->merchant,
                    [
                        'reference' => (string) $settlement->reference,
                        'amount' => NotificationService::money($paid),
                        'outstanding' => NotificationService::money(
                            max(0, $lineTotal - $previouslyAllocated - $allocatedNow),
                        ),
                    ],
                    Permission::SettlementsView,
                );
            }

            // Told only when the batch is FULLY paid off, and only on the
            // move into that state. A partial allocation leaves the store
            // still owing, and announcing "accepted" then would read as "we
            // are done" when they are not.
            if (! $wasSettled && $settlement->state === SettlementState::Settled) {
                $this->notifications->sendToMerchantStaff(
                    NotificationTemplateKey::SettlementAccepted,
                    $settlement->merchant,
                    ['reference' => (string) $settlement->reference],
                    Permission::SettlementsView,
                );
            }

            return $settlement;
        });
    }

    private function locked(Settlement $settlement): Settlement
    {
        return Settlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
    }
}
