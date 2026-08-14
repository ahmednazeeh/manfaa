<?php

namespace App\Http\Resources;

use App\Domain\Customers\CustomerFacingStatus;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transaction as the CUSTOMER sees it (§6 mapping): the simplified status,
 * a translatable reason key, and the amounts that concern them. Internal
 * state and the merchant's commercial terms (fee, fee_bp) never appear here.
 *
 * @mixin Transaction
 */
class CustomerTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant' => [
                'name' => $this->merchant->name,
                'slug' => $this->merchant->slug,
            ],
            'invoice_no' => $this->invoice_no,
            'currency' => $this->currency,
            'eligible_laari' => $this->eligible_laari,
            'cashback_laari' => $this->cashback_laari,
            'status' => CustomerFacingStatus::status($this->state),
            // A key, not prose — the frontend renders e.g.
            // merchant_settlement_window as "Store X settles within 15 days".
            'status_reason' => CustomerFacingStatus::reasonKey($this->resource),
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
