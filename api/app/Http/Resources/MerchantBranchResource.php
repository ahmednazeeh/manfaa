<?php

namespace App\Http\Resources;

use App\Models\MerchantBranch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantBranch
 */
class MerchantBranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'lat' => $this->lat === null ? null : (float) $this->lat,
            'lng' => $this->lng === null ? null : (float) $this->lng,
        ];
    }
}
