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
            // THE CLAIM: what the merchant typed. Never rewritten.
            'amount_laari' => $this->amount_laari,
            'amount_mvr' => Laari::of($this->amount_laari)->formatMvr(),
            // THE FACT: what the bank credited, off the matched statement
            // row — and what actually funded the batch. Null on a pending
            // payment, and on one an admin matched by hand without a figure,
            // where the claim is what was spent.
            'received_laari' => $this->received_laari,
            'received_mvr' => $this->received_laari === null ? null : Laari::of((int) $this->received_laari)->formatMvr(),
            'amount_differs' => $this->amountDiffers(),
            'currency' => $this->currency,
            'method' => $this->method,
            // What the MERCHANT told us. Left exactly as they typed it —
            // often nothing, because the slip carries the reference.
            'bank_ref' => $this->bank_ref,

            // What the BANK says, once matched. Deliberately a separate
            // field rather than backfilled into bank_ref: "the merchant
            // claimed this" and "we found this in the statement" are
            // different facts, and collapsing them loses the ability to
            // tell a confirmed payment from a claimed one.
            //
            // `matched_trx_id` is UNIQUE in the database (partial, where not
            // null), so one bank credit can never settle two payments —
            // that index, not this field, is what makes dedup safe.
            'matched_trx_id' => $this->matched_trx_id,
            // Every identifier that credit answered to. BML files a transfer
            // under an internal statement id but prints a different reference
            // on the merchant's slip, so the id we keyed on is often NOT the
            // one an operator is holding while they reconcile.
            'matched_trx_refs' => $this->matched_trx_refs ?? [],
            'matched_payer_name' => $this->matched_payer_name,
            'matched_score' => $this->matched_score,
            'matched_by_rule' => $this->matched_by_rule,
            'auto_matched' => (bool) $this->auto_matched,
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
