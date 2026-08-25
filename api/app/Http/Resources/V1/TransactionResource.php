<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Money\Laari;
use App\Domain\Money\Percent;
use App\Http\Resources\TransactionLineResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The vendor-facing transaction shape (docs/openapi.yaml, Transaction):
 * laari integers paired with pre-formatted MVR presentation strings, and
 * every timestamp normalised to UTC ISO 8601.
 *
 * EVERY money field carries both halves — `*_laari` is the value and the
 * only thing to compute with, `*_mvr` is the string to print. Pairing some
 * fields and not others is worse than pairing none: it teaches a reader
 * that a bare `_laari` field is already in rufiyaa.
 *
 * Rates follow the same idiom (PLAN §1 wire format): 2-decimal percent
 * strings, never basis points, which are the internal representation only.
 *
 * There are TWO rate pairs, and reading the wrong one is how a mixed basket
 * gets misreported:
 *
 *  - `cashback_rate_percent` / `platform_fee_percent` are the BASE terms
 *    frozen on the row — the store's standing rate (or this sale's
 *    override) at occurred_at, i.e. what the sale was priced AGAINST.
 *  - `effective_cashback_rate_percent` / `effective_platform_fee_percent`
 *    are what it actually EARNED: cashback_laari / eligible_laari.
 *
 * On a single-rate sale the two agree. On a LINED sale they do not, and the
 * base pair is the one that will mislead: a 100,000 basket of 30,000
 * excluded + 25,000 at 2% + 45,000 at the 5% standing rate earns 2,750, so
 * the base rate says "5.00" while the sale really returned "2.75". Each
 * line's own rate and fee are in `lines[]`; print those on a receipt that
 * itemises, and the effective pair when you have room for one number.
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
            // PLAN §1: true means this sale was credited outside the
            // validation window — payable immediately and permanently
            // irreversible through this API (POST /reverse answers 409
            // backdated_irreversible). Vendors branch on this, not on
            // reason_code, which later transitions rewrite.
            'backdated' => (bool) $this->backdated,
            'currency' => $this->currency,
            'eligible_laari' => $this->eligible_laari,
            // Every amount ships as BOTH the authoritative laari integer and
            // a preformatted MVR string. cashback and fee always did;
            // eligible, sale and GST did not, which left a reader dividing
            // 118000 by 100 in their head on the very field the request
            // asked them to send.
            'eligible_mvr' => Laari::of($this->eligible_laari)->formatMvr(),
            'sale_laari' => $this->sale_laari,
            'sale_mvr' => $this->sale_laari === null
                ? null
                : Laari::of($this->sale_laari)->formatMvr(),
            'cashback_rate_percent' => Percent::format($this->rate_bp),
            'platform_fee_percent' => Percent::format($this->fee_bp),
            // Null only when there is nothing to divide by — a rate on a
            // zero eligible amount is undefined, not 0%.
            'effective_cashback_rate_percent' => Percent::effectiveRate(
                $this->cashback_laari,
                $this->eligible_laari,
            ),
            'effective_platform_fee_percent' => Percent::effectiveRate(
                $this->fee_laari,
                $this->eligible_laari,
            ),
            'cashback_laari' => $this->cashback_laari,
            'cashback_mvr' => Laari::of($this->cashback_laari)->formatMvr(),
            'fee_laari' => $this->fee_laari,
            'fee_mvr' => Laari::of($this->fee_laari)->formatMvr(),
            'fee_gst_laari' => $this->fee_gst_laari,
            'fee_gst_mvr' => Laari::of($this->fee_gst_laari)->formatMvr(),
            // The GST terms this sale was FROZEN at, not the platform's
            // current ones — a receipt states what was charged, and a rate
            // change tomorrow must not rewrite what a vendor printed today.
            // "0.00" / "on_top" on every sale priced before GST was switched
            // on, which is every sale so far.
            //
            // `fee_laari` is always Manfaa's NET charge and `fee_gst_laari`
            // always the tax on it: under `on_top` the tax was ADDED to the
            // quoted fee, under `inclusive` it was carved OUT of it. Either
            // way the merchant owes cashback + fee + GST.
            'fee_gst_percent' => Percent::format($this->resource->stampedFeeTax()->rateBp),
            'fee_treatment' => $this->resource->stampedFeeTax()->treatment->value,
            'occurred_at' => $this->occurred_at->utc()->toIso8601String(),
            'received_at' => $this->received_at->utc()->toIso8601String(),
            // Present only when the caller loaded the pricing split (lined
            // credits) — single-rate responses stay byte-identical.
            'lines' => TransactionLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
