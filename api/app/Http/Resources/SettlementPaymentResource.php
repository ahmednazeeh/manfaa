<?php

namespace App\Http\Resources;

use App\Domain\Money\Laari;
use App\Models\SettlementPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SettlementPayment
 */
class SettlementPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_id' => $this->settlement_id,
            'amount_laari' => $this->amount_laari,
            'amount_mvr' => Laari::of($this->amount_laari)->formatMvr(),
            'currency' => $this->currency,
            'method' => $this->method,
            'bank_ref' => $this->bank_ref,
            'slip_path' => $this->slip_path,
            'state' => $this->state,
            'matched_by' => $this->matched_by,
            'matched_at' => $this->matched_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
