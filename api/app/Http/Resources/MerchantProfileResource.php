<?php

namespace App\Http\Resources;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The owner-editable merchant profile. `name` is read-only display —
 * renaming the business is an identity change and stays admin-only.
 * `eligibility_basis` is the §11 free-text mirror of the agreement,
 * displayed to customers, never used in computation.
 *
 * @mixin Merchant
 */
class MerchantProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'category' => $this->category,
            'is_online' => (bool) $this->is_online,
            'eligibility_basis' => $this->eligibility_basis,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
        ];
    }
}
