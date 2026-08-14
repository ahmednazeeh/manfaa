<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Platform\FeeTierScheduleResolver;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One merchant_rates window for the merchant panel, carrying the §4
 * tier-cliff warning data alongside the raw rate: the platform fee the rate
 * lands on and the resulting all-in cost, all integer basis points.
 *
 * The fee comes from the admin-managed fee tier schedule (the same source
 * the billing path prices from), resolved AT the instant the rate is or
 * becomes effective — a currently effective rate at now, a pending rate at
 * its own effective_from. Never the static §4 map: once a published
 * schedule diverges, the static map would tell merchants one fee while
 * their credits freeze another.
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

        $now = CarbonImmutable::now('UTC');
        $effectiveFrom = $rate->effective_from->utc();

        // Lenient on the fee: a legacy stranded rate (no fee priced under
        // the schedule in force) renders with null fee fields instead of
        // failing the whole panel — the rate page IS the merchant's
        // self-rescue path, so it must stay reachable in that state.
        return self::tryDescribeBp($rate->rate_bp, $effectiveFrom->isAfter($now) ? $effectiveFrom : $now) + [
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
     * The §4 cost picture of one rate: fee tier and all-in merchant cost,
     * priced under the fee tier schedule effective at $at (now by default).
     *
     * @return array{rate_bp: int, fee_bp: int, all_in_bp: int}
     */
    public static function describeBp(int $rateBp, ?CarbonImmutable $at = null): array
    {
        $feeBp = app(FeeTierScheduleResolver::class)->feeBpAt($rateBp, $at ?? CarbonImmutable::now('UTC'));

        return [
            'rate_bp' => $rateBp,
            'fee_bp' => $feeBp,
            'all_in_bp' => $rateBp + $feeBp,
        ];
    }

    /**
     * describeBp for surfaces that must survive an UNPRICED rate (a legacy
     * stranded standing rate being displayed or rescued): fee fields are
     * null instead of throwing. The coverage invariant keeps this the rare
     * exception, never the norm.
     *
     * @return array{rate_bp: int, fee_bp: int|null, all_in_bp: int|null}
     */
    public static function tryDescribeBp(int $rateBp, ?CarbonImmutable $at = null): array
    {
        $feeBp = app(FeeTierScheduleResolver::class)->tryFeeBpAt($rateBp, $at ?? CarbonImmutable::now('UTC'));

        return [
            'rate_bp' => $rateBp,
            'fee_bp' => $feeBp,
            'all_in_bp' => $feeBp === null ? null : $rateBp + $feeBp,
        ];
    }
}
