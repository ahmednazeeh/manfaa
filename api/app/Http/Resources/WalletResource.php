<?php

namespace App\Http\Resources;

use App\Domain\Money\Laari;
use App\Models\MerchantWallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantWallet
 */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'balance_laari' => $this->balance_laari,
            'balance_mvr' => Laari::of($this->balance_laari)->formatMvr(),
            'currency' => $this->currency,
            'transactions' => $this->whenLoaded(
                'transactions',
                fn () => $this->transactions->map(fn (WalletTransaction $movement): array => [
                    'id' => $movement->id,
                    'amount_laari' => $movement->amount_laari,
                    'amount_mvr' => Laari::of($movement->amount_laari)->formatMvr(),
                    'balance_after_laari' => $movement->balance_after_laari,
                    'type' => $movement->type,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                    'description' => $movement->description,
                    'created_at' => $movement->created_at->toIso8601String(),
                ])->all(),
            ),
        ];
    }
}
