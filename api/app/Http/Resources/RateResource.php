<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Money\Percent;
use App\Domain\Platform\FeeTierScheduleResolver;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One merchant_rates window for the merchant panel, carrying the §4
 * tier-cliff warning data alongside the rate itself: the platform fee the
 * rate lands on and the resulting all-in cost.
 *
 * WIRE FORMAT (PLAN §1, decision 2026-08-15): every rate here is a
 * 2-decimal percent STRING — `cashback_rate_percent`,
 * `platform_fee_percent`, `all_in_percent`. Basis points are the internal
 * representation and never appear in a response; the conversion is exact
 * integer math (App\Domain\Money\Percent).
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
        return self::tryDescribe($rate->rate_bp, $effectiveFrom->isAfter($now) ? $effectiveFrom : $now) + [
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
     * @return array{cashback_rate_percent: string, platform_fee_percent: string, all_in_percent: string}
     */
    public static function describe(int $rateBp, ?CarbonImmutable $at = null): array
    {
        $feeBp = app(FeeTierScheduleResolver::class)->feeBpAt($rateBp, $at ?? CarbonImmutable::now('UTC'));

        return [
            'cashback_rate_percent' => Percent::format($rateBp),
            'platform_fee_percent' => Percent::format($feeBp),
            'all_in_percent' => Percent::format($rateBp + $feeBp),
        ];
    }

    /**
     * describe() for surfaces that must survive an UNPRICED rate (a legacy
     * stranded standing rate being displayed or rescued): the fee fields
     * are null instead of throwing. The coverage invariant keeps this the
     * rare exception, never the norm.
     *
     * @return array{cashback_rate_percent: string, platform_fee_percent: string|null, all_in_percent: string|null}
     */
    public static function tryDescribe(int $rateBp, ?CarbonImmutable $at = null): array
    {
        $feeBp = app(FeeTierScheduleResolver::class)->tryFeeBpAt($rateBp, $at ?? CarbonImmutable::now('UTC'));

        return [
            'cashback_rate_percent' => Percent::format($rateBp),
            'platform_fee_percent' => Percent::formatOrNull($feeBp),
            'all_in_percent' => $feeBp === null ? null : Percent::format($rateBp + $feeBp),
        ];
    }
}
