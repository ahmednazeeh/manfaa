<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Money\FeeTier;
use App\Models\MerchantRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One merchant_rates window for the merchant panel, carrying the §4
 * tier-cliff warning data alongside the raw rate: the platform fee the rate
 * lands on and the resulting all-in cost, all integer basis points.
 *
 * @property-read MerchantRate $resource
 */
class RateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rate = $this->resource;
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');

        return self::describeBp($rate->rate_bp) + [
            'effective_from' => $rate->effective_from->setTimezone($timezone)->toIso8601String(),
            'effective_to' => $rate->effective_to?->setTimezone($timezone)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forRate(?MerchantRate $rate): ?array
    {
        return $rate === null ? null : (new self($rate))->resolve();
    }

    /**
     * The §4 cost picture of one rate: fee tier and all-in merchant cost.
     *
     * @return array{rate_bp: int, fee_bp: int, all_in_bp: int}
     */
    public static function describeBp(int $rateBp): array
    {
        $feeBp = FeeTier::feeBpFor($rateBp);

        return [
            'rate_bp' => $rateBp,
            'fee_bp' => $feeBp,
            'all_in_bp' => $rateBp + $feeBp,
        ];
    }
}
