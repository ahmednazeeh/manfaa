<?php

namespace App\Http\Resources;

use App\Domain\Money\Laari;
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
            'amount_due_laari' => $this->amount_due_laari,
            'amount_received_laari' => $this->amount_received_laari,
            'cashback_total_mvr' => Laari::of($this->cashback_total_laari)->formatMvr(),
            'fee_total_mvr' => Laari::of($this->fee_total_laari)->formatMvr(),
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
     * Where to actually send the transfer: the platform's active primary
     * bank account (admin-managed, cached briefly) alongside the amount and
     * the reference to quote. When no account is configured the details are
     * null with needs_configuration set — never invented.
     *
     * @return array<string, mixed>
     */
    private function paymentInstructions(): array
    {
        $account = app(BankAccountService::class)->activePrimaryDetails();

        return [
            'reference' => $this->reference,
            'amount_due_laari' => $this->amount_due_laari,
            'amount_due_mvr' => Laari::of($this->amount_due_laari)->formatMvr(),
            'bank_account' => $account === null ? null : [
                'bank_name' => $account['bank_name'],
                'account_no' => $account['account_no'],
                'account_name' => $account['account_name'],
            ],
            'needs_configuration' => $account === null,
        ];
    }
}
