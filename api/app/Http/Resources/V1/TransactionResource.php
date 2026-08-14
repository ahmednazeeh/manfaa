<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Money\Laari;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The vendor-facing transaction shape (docs/openapi.yaml, Transaction):
 * laari integers paired with pre-formatted MVR presentation strings, and
 * every timestamp normalised to UTC ISO 8601.
 *
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
            'currency' => $this->currency,
            'eligible_laari' => $this->eligible_laari,
            'sale_laari' => $this->sale_laari,
            'rate_bp' => $this->rate_bp,
            'fee_bp' => $this->fee_bp,
            'cashback_laari' => $this->cashback_laari,
            'cashback_mvr' => Laari::of($this->cashback_laari)->formatMvr(),
            'fee_laari' => $this->fee_laari,
            'fee_mvr' => Laari::of($this->fee_laari)->formatMvr(),
            'fee_gst_laari' => $this->fee_gst_laari,
            'occurred_at' => $this->occurred_at->utc()->toIso8601String(),
            'received_at' => $this->received_at->utc()->toIso8601String(),
        ];
    }
}
