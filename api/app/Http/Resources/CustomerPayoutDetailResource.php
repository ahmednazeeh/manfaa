<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PayoutItem;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payout plus the purchases it paid for — the screen a customer opens
 * to answer "what was this deposit?".
 *
 * The line total is deliberately not asserted as equal to `amount_laari` in
 * the payload: they ARE equal for a payout built the normal way, and a
 * client that wants to show a total should sum the lines rather than trust
 * a second number to agree. If they ever disagree, the lines are the
 * evidence and the amount is the payment.
 *
 * @mixin PayoutItem
 */
class CustomerPayoutDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'amount_laari' => $this->amount_laari,
            'status' => $this->state->value,
            'failure_reason' => $this->failure_reason,
            'bank' => $this->bank,
            'account_masked' => CustomerPayoutResource::maskAccount($this->account),
            // The transfer reference recorded at mark-paid; the batch-level
            // one is legacy fallback (no longer collected, 3122d40).
            'reference' => $this->bank_reference ?? $this->batch?->reference,
            'period_start' => $this->batch?->period_start?->toDateString(),
            'period_end' => $this->batch?->period_end?->toDateString(),
            'paid_at' => $this->state->value === 'paid'
                ? $this->updated_at?->toIso8601String()
                : null,
            'transactions' => $this->transactions->map(
                fn (Transaction $transaction): array => [
                    'id' => $transaction->id,
                    'invoice_no' => $transaction->invoice_no,
                    'occurred_at' => $transaction->occurred_at->toIso8601String(),
                    'eligible_laari' => $transaction->eligible_laari,
                    'cashback_laari' => $transaction->cashback_laari,
                    'merchant' => [
                        'name' => $transaction->merchant->name,
                        'name_dv' => $transaction->merchant->name_dv,
                        'slug' => $transaction->merchant->slug,
                    ],
                ],
            )->values(),
        ];
    }
}
