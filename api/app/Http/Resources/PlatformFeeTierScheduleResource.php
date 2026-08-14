<?php

namespace App\Http\Resources;

use App\Models\FeeTierSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeeTierSchedule
 */
class PlatformFeeTierScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'effective_from' => $this->effective_from->toIso8601String(),
            'tiers' => $this->tiers,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
