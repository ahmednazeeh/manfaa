<?php

namespace App\Http\Resources;

use App\Domain\Money\Percent;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The merchant panel's transaction shape. Rates are 2-decimal percent
 * strings (PLAN §1 wire format) computed from the frozen basis points.
 *
 * TWO KINDS OF RATE live here, and confusing them is how a lined sale gets
 * read wrong:
 *
 *  - `cashback_rate_percent` / `platform_fee_percent` are the BASE terms
 *    frozen onto the row — the store's standing rate (or the per-sale
 *    override) at occurred_at. On a single-rate sale they are also what the
 *    whole sale earned.
 *  - `effective_cashback_rate_percent` / `effective_platform_fee_percent`
 *    are what the sale ACTUALLY earned, `cashback_laari / eligible_laari`.
 *    On a single-rate sale they equal the base rate (bar sub-laari
 *    rounding); on a MIXED BASKET they do not, because each line priced at
 *    its own category rate and the base rate never applied to the basket
 *    as a whole.
 *
 * A mixed basket of 100,000 laari — 30,000 excluded, 25,000 at 2%, 45,000
 * at the 5% standing rate — earns 2,750, so the base rate reads "5.00"
 * while the effective rate reads "2.75". Per-line truth is in `lines[]`;
 * the effective pair exists so a caller that only wants ONE number for a
 * receipt gets the honest one instead of doing this division itself and
 * disagreeing with us about rounding.
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
            // PLAN §1: credited outside the validation window — payable
            // immediately, and the merchant can never reverse it.
            'backdated' => (bool) $this->backdated,
            'currency' => $this->currency,
            'eligible_laari' => $this->eligible_laari,
            'sale_laari' => $this->sale_laari,
            'cashback_rate_percent' => Percent::format($this->rate_bp),
            'platform_fee_percent' => Percent::format($this->fee_bp),
            'effective_cashback_rate_percent' => Percent::effectiveRate(
                $this->cashback_laari,
                $this->eligible_laari,
            ),
            'effective_platform_fee_percent' => Percent::effectiveRate(
                $this->fee_laari,
                $this->eligible_laari,
            ),
            'cashback_laari' => $this->cashback_laari,
            'fee_laari' => $this->fee_laari,
            'fee_gst_laari' => $this->fee_gst_laari,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'received_at' => $this->received_at->toIso8601String(),
            // Present only when the caller loaded the pricing split (lined
            // credits) — single-rate responses stay byte-identical.
            'lines' => TransactionLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
