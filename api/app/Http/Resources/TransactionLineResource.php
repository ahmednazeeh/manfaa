<?php

namespace App\Http\Resources;

use App\Models\TransactionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One priced line of a lined credit — integer laari and basis points only,
 * with the creation-time category snapshot (`category` is the slug, null
 * for the default "everything else" bucket). Shared by the merchant panel
 * and /v1 transaction shapes.
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
            'effective_rate_bp' => $this->effective_rate_bp,
            'fee_bp' => $this->fee_bp,
            'cashback_laari' => $this->cashback_laari,
            'fee_laari' => $this->fee_laari,
            'priced_by' => $this->priced_by,
            'sort' => $this->sort,
        ];
    }
}
