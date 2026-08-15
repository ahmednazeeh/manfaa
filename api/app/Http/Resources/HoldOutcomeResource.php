<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a hold-queue decision actually did to the transaction.
 *
 * clock_start_at and due_at are in the payload on purpose: a release that
 * lands in payable_unfunded MUST come back with both stamped, so the caller —
 * the panel, a test, an operator with curl — can see the §7 clock running
 * rather than assume it. The defect this queue was built to fix was invisible
 * precisely because nothing ever showed those two columns after a release.
 *
 * @mixin Transaction
 */
class HoldOutcomeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'reason_code' => $this->reason_code,
            'backdated' => (bool) $this->backdated,
            'currency' => $this->currency,
            'cashback_laari' => (int) $this->cashback_laari,
            'fee_laari' => (int) $this->fee_laari,
            'fee_gst_laari' => (int) $this->fee_gst_laari,
            'clock_start_at' => $this->clock_start_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
        ];
    }
}
