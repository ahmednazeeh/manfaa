<?php

namespace App\Http\Resources;

use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A merchant panel account as seen by the owner on the staff screen. No
 * password material ever appears here — the generated temporary password
 * is returned exactly once, on creation, next to (not inside) this
 * resource.
 *
 * @mixin MerchantUser
 */
class MerchantStaffResource extends JsonResource
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
            'is_active' => (bool) ($this->is_active ?? true),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
