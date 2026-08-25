<?php

namespace App\Http\Resources;

use App\Domain\Money\Laari;
use App\Domain\Money\Percent;
use App\Domain\Platform\BankAccountService;
use App\Domain\Settlement\SettlementState;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Settlement
 */
class SettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'state' => $this->state->value,
            'funding_method' => $this->funding_method,
            'currency' => $this->currency,
            'sale_total_laari' => $this->sale_total_laari,
            'cashback_total_laari' => $this->cashback_total_laari,
            'fee_total_laari' => $this->fee_total_laari,
            'fee_gst_total_laari' => $this->fee_gst_total_laari,
            // PLAN §1 prompt-payment discount, as GRANTED at submit: the
            // relief already subtracted from amount_due, the rate it was
            // priced at (null when nothing was granted), and the machine
            // reason — which the panels turn into "you saved MVR 1.62" or
            // "settle everything to save 5% of the fee".
            'discount_laari' => (int) $this->discount_laari,
            'discount_mvr' => Laari::of((int) $this->discount_laari)->formatMvr(),
            'discount_rate_percent' => Percent::formatOrNull(
                $this->discount_rate_bp === null ? null : (int) $this->discount_rate_bp,
            ),
            'discount_reason' => $this->discount_reason,
            'amount_due_laari' => $this->amount_due_laari,
            'amount_received_laari' => $this->amount_received_laari,
            'cashback_total_mvr' => Laari::of($this->cashback_total_laari)->formatMvr(),
            'fee_total_mvr' => Laari::of($this->fee_total_laari)->formatMvr(),
            // Broken out, never blended into the fee (owner, 2026-08-24).
            // `fee_total_*` is Manfaa's own charge and this is the tax on
            // it; the merchant owes cashback + fee + GST, which is
            // amount_due. Zero on every batch priced before GST was
            // switched on — those rows carry their own stamped rate.
            'fee_gst_total_mvr' => Laari::of((int) $this->fee_gst_total_laari)->formatMvr(),
            'amount_due_mvr' => Laari::of($this->amount_due_laari)->formatMvr(),
            'amount_received_mvr' => Laari::of($this->amount_received_laari)->formatMvr(),
            'due_at' => $this->due_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'payment_instructions' => $this->paymentInstructions(),
            'merchant_status' => $this->merchantStatus(),
            'lines' => SettlementLineResource::collection($this->whenLoaded('lines')),
            'payments' => SettlementPaymentResource::collection($this->whenLoaded('payments')),
        ];
    }

    /**
     * What the merchant is actually told (PLAN §1 receipt-first). The raw
     * §6 state stays in `state` for machines; this is the human answer to
     * "what is happening to my transfer", including — when an admin rejected
     * the receipt — WHY, so the merchant can fix it and submit a new batch
     * instead of guessing.
     *
     * A cancelled batch is only "rejected" when a rejected payment says so;
     * a plain cancellation (no payment ever recorded) is not a refusal of
     * anything and must not be dressed up as one.
     *
     * @return array<string, mixed>
     */
    private function merchantStatus(): array
    {
        $rejection = $this->rejection();

        [$code, $message] = match (true) {
            $rejection !== null => ['rejected', 'Manfaa could not verify your transfer — see the reason, then submit a new settlement.'],
            $this->state === SettlementState::PaymentReview => ['verifying', 'Manfaa is verifying your transfer.'],
            $this->state === SettlementState::Settled => ['settled', 'Settled — the rewards on this batch are confirmed.'],
            $this->state === SettlementState::PartiallySettled => ['partially_settled', 'Part of this batch is settled; the remaining rewards are still pending.'],
            $this->state === SettlementState::AwaitingPayment => ['awaiting_payment', 'Awaiting your transfer.'],
            $this->state === SettlementState::Cancelled => ['cancelled', 'This settlement was cancelled; its transactions are payable again.'],
            default => ['draft', 'Draft — not yet submitted.'],
        };

        return [
            'code' => $code,
            'message' => $message,
            'rejection' => $rejection,
        ];
    }

    /**
     * The rejection that cancelled this batch, read off the payment the admin
     * refused. Only when the payments relation is loaded — a resource never
     * fires a hidden query per row.
     *
     * @return array<string, mixed>|null
     */
    private function rejection(): ?array
    {
        if ($this->state !== SettlementState::Cancelled || ! $this->relationLoaded('payments')) {
            return null;
        }

        /** @var SettlementPayment|null $rejected */
        $rejected = $this->payments
            ->where('state', 'rejected')
            ->sortByDesc('id')
            ->first();

        if ($rejected === null) {
            return null;
        }

        return [
            'reason' => $rejected->rejection_reason,
            'rejected_at' => $rejected->rejected_at?->toIso8601String(),
            'bank_ref' => $rejected->bank_ref,
            'payment_id' => $rejected->id,
        ];
    }

    /**
     * Where the transfer went, or should go: the account the merchant CHOSE
     * when they submitted, alongside the amount and the reference to quote.
     * Every active account rides along so a panel can name them all.
     *
     * The fallback is only for batches that predate the choice. Quoting
     * today's default at a settlement paid into a different account is how a
     * reconciliation goes looking at the wrong statement. When nothing is
     * configured at all the details are null with needs_configuration set —
     * never invented.
     *
     * @return array<string, mixed>
     */
    private function paymentInstructions(): array
    {
        $service = app(BankAccountService::class);

        $chosen = $this->platform_bank_account_id === null
            ? null
            : collect($service->activeAccounts())
                ->firstWhere('id', $this->platform_bank_account_id);

        $accounts = $service->activeAccounts();
        $account = $chosen ?? ($accounts[0] ?? null);

        return [
            'reference' => $this->reference,
            'amount_due_laari' => $this->amount_due_laari,
            'amount_due_mvr' => Laari::of($this->amount_due_laari)->formatMvr(),
            'bank_account' => $account === null ? null : [
                'bank_name' => $account['bank_name'],
                'account_no' => $account['account_no'],
                'account_name' => $account['account_name'],
            ],
            'bank_accounts' => $accounts,
            'needs_configuration' => $account === null,
        ];
    }
}
