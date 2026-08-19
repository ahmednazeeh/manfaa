<?php

namespace App\Http\Resources;

use App\Models\PayoutBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayoutBatch
 */
class PayoutBatchResource extends JsonResource
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
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'cutoff_at' => $this->cutoff_at->toIso8601String(),
            'total_laari' => $this->total_laari,
            'currency' => $this->currency,
            'customer_count' => $this->customer_count,
            // Money waiting on bank details: eligible customers skipped at
            // build time because payout_bank/account/name were incomplete.
            'excluded_customer_count' => $this->excluded_customer_count,
            'excluded_total_laari' => $this->excluded_total_laari,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'exported_at' => $this->exported_at?->toIso8601String(),
            // Distinct from exported_at on purpose: two roads to the bank,
            // and the page should say which one this batch took.
            'api_sent_at' => $this->api_sent_at?->toIso8601String(),
            'items' => PayoutItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
