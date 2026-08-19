<?php

namespace App\Http\Resources;

use App\Models\PayoutItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayoutItem
 */
class PayoutItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'customer_id' => $this->customer_id,
            // The key the transfer sheet is matched on, and the customer as
            // they were when the batch was built — both snapshots, so the
            // items table names a person rather than a row id.
            'idempotency_key' => $this->idempotency_key,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'amount_laari' => $this->amount_laari,
            'currency' => $this->currency,
            'bank' => $this->bank,
            'account' => $this->account,
            'account_name' => $this->account_name,
            'state' => $this->state->value,
            'failure_reason' => $this->failure_reason,
            'bank_reference' => $this->bank_reference,
            // What the bank API said, when the batch went out that way.
            'attempts' => (int) $this->attempts,
            'error_code' => $this->error_code,
            // An approvals-queue record id. Not a transaction reference, and
            // never shown as one.
            'approval_id' => $this->approval_id,
        ];
    }
}
