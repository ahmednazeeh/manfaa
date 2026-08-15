<?php

namespace App\Http\Resources;

use App\Domain\Money\Percent;
use App\Models\FeeTierSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One effective-dated §4 fee tier table for the admin panel.
 *
 * The table is STORED as integer basis points ({from_bp, to_bp, fee_bp},
 * append-only) and is presented here as 2-decimal percent strings
 * ({from_percent, to_percent, fee_percent}) — PLAN §1 wire format: basis
 * points never appear in a response body.
 *
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
            'tiers' => self::tiers((array) $this->tiers),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, mixed>  $tiers  the stored basis-point bands
     * @return list<array{from_percent: string, to_percent: string, fee_percent: string}>
     */
    public static function tiers(array $tiers): array
    {
        return array_values(array_map(
            fn (array $tier): array => [
                'from_percent' => Percent::format((int) $tier['from_bp']),
                'to_percent' => Percent::format((int) $tier['to_bp']),
                'fee_percent' => Percent::format((int) $tier['fee_bp']),
            ],
            $tiers,
        ));
    }
}
