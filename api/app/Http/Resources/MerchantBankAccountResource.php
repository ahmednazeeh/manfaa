<?php

namespace App\Http\Resources;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The merchant's own bank identity — bank_name / bank_account /
 * bank_account_name.
 *
 * Purpose: matching INBOUND settlement payments (the admin matching queue
 * verifies the payer against this identity) and future wallet withdrawals.
 * Used in both directions: matching the merchant -> platform settlement
 * transfers (the ordinary flow, cashback + fee), and as the destination for
 * anything the platform returns — a refund, an overpayment, a correction.
 *
 * @mixin Merchant
 */
class MerchantBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'bank_name' => $this->bank_name,
            'bank_account' => $this->bank_account,
            'bank_account_name' => $this->bank_account_name,
        ];
    }
}
