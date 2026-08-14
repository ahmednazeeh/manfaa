<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Money\FeeTier;
use App\Models\Merchant;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/merchants/me/rate — the till's advisory rate display (§9.2). The
 * server always recomputes authoritatively at occurred_at; what the till
 * caches can only ever under-promise, because rate DECREASES take effect at
 * 00:00 next day (business timezone) and are surfaced here ahead of time as
 * pending_decrease. Increases apply immediately and never appear pending.
 */
class MerchantRateController extends V1Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $now = CarbonImmutable::now('UTC');

        $current = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($current === null) {
            return $this->error(422, 'no_effective_rate', 'No cashback rate is currently effective — contact the platform.');
        }

        $pending = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '>', $now)
            ->orderBy('effective_from')
            ->first();

        $pendingDecrease = null;

        if ($pending !== null && $pending->rate_bp < $current->rate_bp) {
            $pendingDecrease = [
                'rate_bp' => $pending->rate_bp,
                'fee_bp' => FeeTier::feeBpFor($pending->rate_bp),
                'effective_at' => $pending->effective_from
                    ->setTimezone((string) config('app.business_timezone', 'Indian/Maldives'))
                    ->toIso8601String(),
            ];
        }

        return new JsonResponse([
            'rate_bp' => $current->rate_bp,
            'fee_bp' => FeeTier::feeBpFor($current->rate_bp),
            'currency' => 'MVR',
            'min_eligible_laari' => $merchant->min_eligible_laari,
            'pending_decrease' => $pendingDecrease,
        ]);
    }
}
