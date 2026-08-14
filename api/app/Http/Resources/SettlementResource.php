<?php

namespace App\Http\Resources;

use App\Domain\Money\Laari;
use App\Models\Settlement;
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
            'lines' => SettlementLineResource::collection($this->whenLoaded('lines')),
            'payments' => SettlementPaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
