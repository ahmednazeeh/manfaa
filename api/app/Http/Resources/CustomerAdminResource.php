<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One customer row in the admin list: what a support call needs to FIND the
 * account and read its standing at a glance. The full phone is shown — it is
 * the login identity this surface exists to verify and correct — while the
 * payout account stays off the list entirely (the detail shows it masked).
 *
 * @mixin Customer
 */
class CustomerAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'name' => $this->name,
            'phone' => $this->phone,
            'status' => $this->status,
            'kyc_status' => $this->kyc_status,
            // Same three-field test the balance screen applies: a payout
            // needs a bank, a number and a name, or the batch skips them.
            'has_payout_account' => filled($this->payout_bank)
                && filled($this->payout_account)
                && filled($this->payout_account_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
