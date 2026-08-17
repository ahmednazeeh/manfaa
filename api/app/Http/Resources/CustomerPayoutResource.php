<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PayoutItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payout as the CUSTOMER sees it: an amount that went to their bank,
 * when, for which earning period, and how many purchases it covered.
 *
 * The bank account is shown MASKED. The customer supplied it, so it is not
 * a secret from them — but a payout list is the kind of screen people
 * screenshot and send to support, and a full account number has no business
 * being in that screenshot.
 *
 * Nothing about the batch's internals (its id, its approval chain, the
 * other customers in it) crosses this boundary; `reference` is the only
 * batch fact a customer needs, because it is what appears on their bank
 * statement.
 *
 * @mixin PayoutItem
 */
class CustomerPayoutResource extends JsonResource
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
            // sent | paid | failed — the frontend translates; a raw state
            // never reaches the screen.
            'status' => $this->state->value,
            'failure_reason' => $this->failure_reason,
            'bank' => $this->bank,
            'account_masked' => self::maskAccount($this->account),
            // What the customer's bank statement shows against the credit:
            // the transfer reference the admin recorded at mark-paid. The
            // batch-creation reference stopped being collected (3122d40),
            // so the item's own bank_reference is the truth; the batch one
            // remains only as a legacy fallback.
            'reference' => $this->bank_reference ?? $this->batch?->reference,
            'period_start' => $this->batch?->period_start?->toDateString(),
            'period_end' => $this->batch?->period_end?->toDateString(),
            'transaction_count' => $this->whenCounted('transactions'),
            'paid_at' => $this->state->value === 'paid'
                ? $this->updated_at?->toIso8601String()
                : null,
        ];
    }

    /** Last four digits only: "•••• 4821". Null stays null. */
    public static function maskAccount(?string $account): ?string
    {
        if ($account === null || $account === '') {
            return null;
        }

        return '•••• '.mb_substr($account, -4);
    }
}
