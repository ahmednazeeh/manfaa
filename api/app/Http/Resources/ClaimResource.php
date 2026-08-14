<?php

namespace App\Http\Resources;

use App\Models\Claim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A claim as its OWNING customer sees it. The resolution note is shown —
 * rejection reasons are factual wording (§9.4) — but resolver identity and
 * other internals stay out.
 *
 * @mixin Claim
 */
class ClaimResource extends JsonResource
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
            'purchased_at' => $this->claimed_date->toDateString(),
            'amount_laari' => $this->claimed_amount_laari,
            'currency' => $this->currency,
            'receipt_no' => $this->receipt_no,
            'note' => $this->note,
            'state' => $this->state,
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
