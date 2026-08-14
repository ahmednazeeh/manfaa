<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Money\Laari;
use App\Models\Adjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The vendor-facing adjustment shape (docs/openapi.yaml, Adjustment).
 * amount_laari is the stored line total, negated — a credit on the
 * merchant's next settlement batch.
 *
 * @mixin Adjustment
 */
class AdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'amount_laari' => $this->amount_laari,
            'amount_mvr' => Laari::of($this->amount_laari)->formatMvr(),
            'currency' => $this->currency,
            'reason_code' => $this->reason_code,
            'created_at' => $this->created_at->toImmutable()->utc()->toIso8601String(),
        ];
    }
}
