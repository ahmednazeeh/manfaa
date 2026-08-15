<?php

namespace App\Http\Resources;

use App\Domain\Money\Percent;
use App\Models\TransactionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One priced line of a lined credit — integer laari plus the creation-time
 * category snapshot (`category` is the slug, null for the default
 * "everything else" bucket). Shared by the merchant panel and /v1
 * transaction shapes.
 *
 * `cashback_rate_percent` is the rate this LINE actually priced at (0.00
 * for an excluded category); `platform_fee_percent` is the fee that
 * followed it. Both are 2-decimal percent strings (PLAN §1 wire format) —
 * the stored basis points never reach the wire.
 *
 * @mixin TransactionLine
 */
class TransactionLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'category' => $this->category_slug,
            'category_name_en' => $this->category_name_en,
            'amount_laari' => $this->amount_laari,
            'cashback_rate_percent' => Percent::format($this->effective_rate_bp),
            'platform_fee_percent' => Percent::format($this->fee_bp),
            'cashback_laari' => $this->cashback_laari,
            'fee_laari' => $this->fee_laari,
            'priced_by' => $this->priced_by,
            'sort' => $this->sort,
        ];
    }
}
