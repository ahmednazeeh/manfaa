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
            // Receipt-first (PLAN §1): the mime and size are what the BYTES
            // said at upload, never the client's filename or Content-Type.
            // has_slip is what a UI should branch on — slip_path is a private
            // disk path, not something anything can fetch.
            'has_slip' => $this->slip_path !== null,
            'slip_mime' => $this->slip_mime,
            'slip_size_bytes' => $this->slip_size_bytes,
            'uploaded_by' => $this->uploaded_by,
            'state' => $this->state,
            'matched_by' => $this->matched_by,
            'matched_at' => $this->matched_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
