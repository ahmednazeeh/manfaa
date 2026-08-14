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
            'created_by' => $this->created_by,
            'approved_by_first' => $this->approved_by_first,
            'approved_by_second' => $this->approved_by_second,
            'first_approved_at' => $this->first_approved_at?->toIso8601String(),
            'second_approved_at' => $this->second_approved_at?->toIso8601String(),
            'exported_at' => $this->exported_at?->toIso8601String(),
            'items' => PayoutItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
