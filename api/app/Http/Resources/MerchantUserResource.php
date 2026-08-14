<?php

namespace App\Http\Resources;

use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantUser
 */
class MerchantUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'merchant' => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->name,
                // The onboarding lifecycle status — the panel routes draft /
                // rejected owners into the setup wizard and pending_review
                // ones onto the waiting screen.
                'status' => $this->merchant->status,
            ],
        ];
    }
}
