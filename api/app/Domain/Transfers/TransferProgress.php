<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementState;
use App\Models\MerchantWallet;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\SettlementPayment;
use App\Models\WalletTopUp;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ONE payload for "what is happening to the transfer I just uploaded",
 * whether that transfer is settling a batch or topping up the wallet
 * (owner, 2026-08-25).
 *
 * The two merchant screens — settlement_pay_screen and wallet_top_up_screen
 * — used to park on a static "Manfaa is verifying your transfer." while the
 * server was really polling the bank behind them. These endpoints are what
 * they observe instead: whether a watch is genuinely running, how long it
 * has left, how many times the bank has been asked, and then the REAL
 * outcome on the same screen.
 *
 * ONE SHAPE, ON PURPOSE. The envelope below is identical for both flows and
 * is built in exactly one place, so the two clients share their polling code
 * and neither payload can drift away from the other. Only `outcome` differs,
 * because only the outcomes differ: a batch is settled or partly settled, a
 * wallet is credited.
 *
 * WHAT THIS IS NOT: it is not a second source of truth about the transfer.
 * Nothing here writes, nothing here decides, and nothing here starts or
 * stops a poll. The server watches the bank whether or not a screen is
 * open — the screen merely looks. Every field is read off the row the
 * pollers themselves maintain.
 *
 * NO MERCHANT-FACING PROSE LIVES HERE. `reason` and `outcome.result` are
 * machine values; the two apps and the panel word them in their own
 * en + dv copy. A sentence invented here would be English-only and would
 * have to be re-translated in three clients that already own their strings.
 */
final class TransferProgress
{
    public const string KIND_SETTLEMENT = 'settlement_payment';

    public const string KIND_TOP_UP = 'wallet_top_up';

    public function __construct(private readonly BankWatch $watch) {}

    /**
     * The newest bank payment on a batch, and what the batch became.
     *
     * @return array<string, mixed>
     */
    public function forSettlementPayment(Settlement $settlement, SettlementPayment $payment): array
    {
        return $this->envelope(
            kind: self::KIND_SETTLEMENT,
            id: (int) $payment->getKey(),
            settlementId: (int) $settlement->getKey(),
            state: (string) $payment->state,
            amountLaari: (int) $payment->amount_laari,
            watchStartedAt: $payment->poll_started_at,
            watchUntil: $payment->poll_until,
            attempts: (int) $payment->poll_attempts,
            autoMatched: (bool) $payment->auto_matched,
            decidedAt: $payment->matched_at ?? $payment->rejected_at,
            platformBankAccountId: $settlement->platform_bank_account_id === null
                ? null
                : (int) $settlement->platform_bank_account_id,
            outcome: $this->settlementOutcome($settlement, $payment),
        );
    }

    /**
     * A wallet top-up claim, and — once credited — the sentence the screen
     * wants to print: how much went in, and what the balance is now.
     *
     * @return array<string, mixed>
     */
    public function forWalletTopUp(WalletTopUp $topUp): array
    {
        return $this->envelope(
            kind: self::KIND_TOP_UP,
            id: (int) $topUp->getKey(),
            // A top-up funds no batch: the field stays in the shared shape
            // so one client parser reads both, and stays null here.
            settlementId: null,
            state: (string) $topUp->state,
            amountLaari: (int) $topUp->amount_laari,
            watchStartedAt: $topUp->poll_started_at,
            watchUntil: $topUp->poll_until,
            attempts: (int) $topUp->poll_attempts,
            autoMatched: (bool) $topUp->auto_matched,
            decidedAt: $topUp->matched_at ?? $topUp->rejected_at,
            platformBankAccountId: $topUp->platform_bank_account_id === null
                ? null
                : (int) $topUp->platform_bank_account_id,
            outcome: $this->topUpOutcome($topUp),
        );
    }

    /**
     * The shared shape. Every field means the same thing on both flows.
     *
     * `checked_at` is the SERVER's clock at the moment of the read, and it
     * is here so a countdown against `watch_until` cannot be thrown off by
     * a handset whose own clock is minutes out — the client subtracts one
     * from the other rather than from its own now().
     *
     * `attempts` = 0 while `watching` is true means the job is queued but
     * has not asked the bank yet. That is an ordinary first second, not a
     * fault, and a client should not treat it as one.
     *
     * @param  array<string, mixed>|null  $outcome  null until the row is terminal
     * @return array<string, mixed>
     */
    private function envelope(
        string $kind,
        int $id,
        ?int $settlementId,
        string $state,
        int $amountLaari,
        ?CarbonInterface $watchStartedAt,
        ?CarbonInterface $watchUntil,
        int $attempts,
        bool $autoMatched,
        ?CarbonInterface $decidedAt,
        ?int $platformBankAccountId,
        ?array $outcome,
    ): array {
        [$watching, $reason] = $this->watch->on($state, $watchUntil, $platformBankAccountId);

        return [
            'kind' => $kind,
            'id' => $id,
            'settlement_id' => $settlementId,
            'state' => $state,
            // THE CLAIM: what the merchant typed when they uploaded the
            // slip. Unchanged by a match on purpose — the bank's figure
            // lives in the outcome, beside it (`received_laari`), so a
            // client reading only the envelope cannot mistake one for the
            // other.
            'amount_laari' => $amountLaari,
            'amount_mvr' => Laari::of($amountLaari)->formatMvr(),
            'watching' => $watching,
            // Null exactly when watching is true. Never both.
            'reason' => $reason,
            'watch_started_at' => $watchStartedAt?->toIso8601String(),
            'watch_until' => $watchUntil?->toIso8601String(),
            'attempts' => $attempts,
            // True only when the BANK's own history matched this transfer.
            // An admin's reconciliation leaves it false, and the screen may
            // legitimately say so differently.
            'auto_matched' => $autoMatched,
            'decided_at' => $decidedAt?->toIso8601String(),
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'outcome' => $outcome,
        ];
    }

    /**
     * The merchant's CLAIM and the bank's FACT, in the one shape both
     * outcomes carry (owner, 2026-08-25).
     *
     * Built here rather than twice, for the same reason the envelope is: two
     * copies of a money payload drift, and this pair is precisely the one a
     * merchant will be reading when the numbers disagree.
     *
     * `amount_differs` is the client's branch. It is FALSE while no bank
     * figure is known — an unknown is not a discrepancy — so a screen never
     * announces a mismatch it cannot name both sides of.
     *
     * @return array<string, mixed>
     */
    private function claimAndFact(int $claimedLaari, ?int $receivedLaari, bool $differs): array
    {
        return [
            'claimed_laari' => $claimedLaari,
            'claimed_mvr' => Laari::of($claimedLaari)->formatMvr(),
            'received_laari' => $receivedLaari,
            'received_mvr' => $receivedLaari === null ? null : Laari::of($receivedLaari)->formatMvr(),
            'amount_differs' => $differs,
        ];
    }

    /**
     * What the batch became. Null while the payment is still pending — an
     * outcome that does not exist yet is never invented.
     *
     * The honest part: a match that covered only some lines reports
     * `partially_settled` with what is STILL OWED, never `settled`. §7
     * allocates whole lines only, so a merchant who transferred less than
     * the batch's due has a real remainder to send and must be told the
     * number rather than congratulated.
     *
     * @return array<string, mixed>|null
     */
    private function settlementOutcome(Settlement $settlement, SettlementPayment $payment): ?array
    {
        if ($payment->state === 'pending') {
            return null;
        }

        $rejected = $payment->state === 'rejected';

        $received = (int) $settlement->amount_received_laari;
        $outstanding = match (true) {
            $settlement->state === SettlementState::Settled => 0,
            // A refused receipt cancels the batch and releases its lines
            // (SettlementBuilder::reject). Nothing is owed on THIS batch any
            // more, and printing its due as outstanding would send the
            // merchant to transfer money against a dead reference.
            $settlement->state === SettlementState::Cancelled => 0,
            default => $this->stillOwed($settlement, $received),
        };

        return [
            'result' => match (true) {
                $rejected => 'rejected',
                $settlement->state === SettlementState::Settled => 'settled',
                default => 'partially_settled',
            },
            // THIS payment's two figures (owner, 2026-08-25). The bank's is
            // what funded the batch; the merchant's is what they typed. A
            // screen that printed only the claim would explain a
            // partially_settled batch with the very number that does not
            // account for it. `received_*` is null where no bank figure is
            // known — a hand-matched payment — and `amount_differs` is then
            // false, because nothing is known to differ.
            ...$this->claimAndFact((int) $payment->amount_laari, $payment->received_laari, $payment->amountDiffers()),
            // The raw §6 state alongside the verdict, for a client that
            // wants to branch on the batch rather than on this payment.
            'settlement_state' => $settlement->state->value,
            'reference' => $settlement->reference,
            // The BATCH's running total, not this payment's amount — that
            // is `amount_laari` at the top level.
            'amount_received_laari' => $received,
            'amount_received_mvr' => Laari::of($received)->formatMvr(),
            'amount_outstanding_laari' => $outstanding,
            'amount_outstanding_mvr' => Laari::of($outstanding)->formatMvr(),
            // settlement_payments spells it `rejection_reason` and
            // wallet_top_ups spells it `rejected_reason`; the wire says
            // `rejected_reason` for both so one client parser serves both.
            'rejected_reason' => $rejected ? $payment->rejection_reason : null,
        ];
    }

    /**
     * THE FURTHER TRANSFER THAT FINISHES THIS BATCH — the number a partly
     * settled screen prints, and therefore a number that has to be right to
     * the laari.
     *
     * It is NOT `amount_due − amount_received`. That subtraction counts a
     * previous payment's unallocated remainder as if it were still available
     * to this batch, but the remainder is parked in the merchant's WALLET
     * (SettlementAllocator: `$remainder > 0` → wallet credit), and the wallet
     * is spendable elsewhere — the hourly auto-settler and the merchant's own
     * settle-from-wallet button both eat it. Once it is spent, `due −
     * received` understates the remainder; on a batch whose received has
     * reached its due it reports 0 owed on a batch that is not settled, and
     * both clients then tell the merchant to "transfer exactly MVR 0.00".
     *
     * So it is computed the way {@see SettlementAllocator::matchPayment}
     * itself computes funds: what the LINES still want, less the funding
     * genuinely still available to them —
     *
     *   • the §7 adjustment credit netted in at draft time and not yet eaten
     *     by earlier allocations;
     *   • the prompt-payment discount that has not been posted yet;
     *   • this batch's own parked remainder, but only as far as the wallet
     *     really still holds it (`min(priorUnallocated, balance)`, the
     *     allocator's own cap — the wallet is never driven below zero on
     *     behalf of a match).
     *
     * Two indexed reads, both on ids: one aggregate over the batch's lines,
     * one wallet balance. This is polled every five seconds, so nothing here
     * loads a model it does not need.
     */
    private function stillOwed(Settlement $settlement, int $received): int
    {
        /** @var object{line_total: int|string|null, allocated_total: int|string|null} $sums */
        $sums = SettlementLine::query()
            ->where('settlement_id', $settlement->getKey())
            ->selectRaw('COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari), 0) as line_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN allocated_at IS NULL THEN 0 ELSE cashback_laari + fee_laari + fee_gst_laari END), 0) as allocated_total')
            ->first();

        $lineTotal = (int) ($sums->line_total ?? 0);
        $allocated = (int) ($sums->allocated_total ?? 0);
        $remainingDue = max(0, $lineTotal - $allocated);

        if ($remainingDue === 0) {
            return 0;
        }

        $discount = (int) $settlement->discount_laari;
        $discountPosted = (int) $settlement->discount_posted_laari;
        $discountAvailable = max(0, $discount - $discountPosted);

        // amount_due = line total − applied §7 credits − discount, so the
        // credits are what is left over. Already posted at application: they
        // fund lines without any further money arriving.
        $adjustmentCredit = max(0, $lineTotal - (int) $settlement->amount_due_laari - $discount);
        $adjustmentAvailable = max(0, $adjustmentCredit - $allocated);

        // Cash that earlier allocations really consumed, and therefore what
        // of this batch's received cash was parked rather than spent.
        $priorCashConsumed = max(0, $allocated - $adjustmentCredit - $discountPosted);
        $priorUnallocated = max(0, $received - $priorCashConsumed);

        $balance = (int) MerchantWallet::query()
            ->where('merchant_id', $settlement->merchant_id)
            ->value('balance_laari');

        $walletAvailable = min($priorUnallocated, max(0, $balance));

        return max(0, $remainingDue - $adjustmentAvailable - $discountAvailable - $walletAvailable);
    }

    /**
     * What the wallet became. Null while the claim is still pending.
     *
     * `balance_laari` is the wallet's balance AT THE MOMENT OF THIS READ,
     * not a snapshot taken when the credit landed. That is the honest answer
     * to "balance now": if the hourly auto-settle spent the money between
     * the credit and the merchant looking, the screen must show what is
     * really there rather than a figure that was true a minute ago.
     *
     * @return array<string, mixed>|null
     */
    private function topUpOutcome(WalletTopUp $topUp): ?array
    {
        if ($topUp->state === 'pending') {
            return null;
        }

        // WHAT WENT IN, not what was asked for (owner, 2026-08-25). A screen
        // congratulating a merchant on the MVR 20.00 they typed while MVR
        // 10.00 sits in the wallet is the lie this round exists to end.
        $credited = $topUp->state === 'matched' ? $topUp->creditedLaari() : 0;

        $balance = (int) MerchantWallet::query()
            ->where('merchant_id', $topUp->merchant_id)
            ->value('balance_laari');

        return [
            'result' => $topUp->state === 'matched' ? 'credited' : 'rejected',
            'credited_laari' => $credited,
            'credited_mvr' => Laari::of($credited)->formatMvr(),
            // The claim beside the fact, so the screen can say WHY the
            // credited figure is not the one they typed rather than leaving
            // them to work it out from a balance.
            ...$this->claimAndFact((int) $topUp->amount_laari, $topUp->received_laari, $topUp->amountDiffers()),
            'balance_laari' => $balance,
            'balance_mvr' => Laari::of($balance)->formatMvr(),
            'rejected_reason' => $topUp->rejected_reason,
        ];
    }
}
