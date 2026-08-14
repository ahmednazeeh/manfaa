<?php

namespace App\Http\Resources;

use App\Models\Claim;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A claim as the admin queue sees it: everything the customer view shows,
 * plus who filed it, who resolved it, and the resulting transaction link.
 *
 * @mixin Claim
 */
class ClaimAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'merchant' => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->name,
                'slug' => $this->merchant->slug,
            ],
            'customer' => [
                'id' => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'name' => $this->customer->name,
            ],
            'purchased_at' => $this->claimed_date->toDateString(),
            'amount_laari' => $this->claimed_amount_laari,
            'currency' => $this->currency,
            'receipt_no' => $this->receipt_no,
            'note' => $this->note,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at !== null
                ? CarbonImmutable::parse($this->resolved_at)->toIso8601String()
                : null,
            'resolution_note' => $this->resolution_note,
            'resulting_transaction_id' => $this->resulting_transaction_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
