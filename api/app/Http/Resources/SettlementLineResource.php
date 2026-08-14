<?php

namespace App\Http\Resources;

use App\Domain\Money\Laari;
use App\Models\SettlementLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SettlementLine
 */
class SettlementLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $due = $this->cashback_laari + $this->fee_laari + $this->fee_gst_laari;

        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'currency' => $this->currency,
            'cashback_laari' => $this->cashback_laari,
            'fee_laari' => $this->fee_laari,
            'fee_gst_laari' => $this->fee_gst_laari,
            'due_laari' => $due,
            'due_mvr' => Laari::of($due)->formatMvr(),
            'allocated_at' => $this->allocated_at !== null
                ? CarbonImmutable::parse($this->allocated_at)->toIso8601String()
                : null,
            'transaction' => new TransactionResource($this->whenLoaded('transaction')),
        ];
    }
}
