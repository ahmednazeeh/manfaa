<?php

namespace App\Http\Resources;

use App\Domain\Customers\CustomerAvatar;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
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
            'phone' => $this->maskedPhone(),
            'status' => $this->status,
            'kyc_status' => $this->kyc_status,
            // Null until the customer sets a picture. Content-addressed
            // (uuid per upload), so render it without cache-busting.
            'avatar_url' => CustomerAvatar::url($this->resource),
        ];
    }

    /**
     * Keep the dialling prefix and last three digits; star the rest.
     */
    private function maskedPhone(): string
    {
        $phone = (string) $this->phone;

        if (strlen($phone) <= 7) {
            return $phone;
        }

        return substr($phone, 0, 4)
            .str_repeat('*', strlen($phone) - 7)
            .substr($phone, -3);
    }
}
