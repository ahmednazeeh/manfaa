<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'origin' => $this->origin,
            'invoice_no' => $this->invoice_no,
            'state' => $this->state->value,
            'reason_code' => $this->reason_code,
            // PLAN §1: credited outside the validation window — payable
            // immediately, and the merchant can never reverse it.
            'backdated' => (bool) $this->backdated,
            'currency' => $this->currency,
            'eligible_laari' => $this->eligible_laari,
            'sale_laari' => $this->sale_laari,
            'rate_bp' => $this->rate_bp,
            'fee_bp' => $this->fee_bp,
            'cashback_laari' => $this->cashback_laari,
            'fee_laari' => $this->fee_laari,
            'fee_gst_laari' => $this->fee_gst_laari,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'received_at' => $this->received_at->toIso8601String(),
            // Present only when the caller loaded the pricing split (lined
            // credits) — single-rate responses stay byte-identical.
            'lines' => TransactionLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
