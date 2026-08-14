<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Money\FeeTier;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Domain\Webhooks\WebhookEvents;
use App\Http\Controllers\Controller;
use App\Http\Resources\RateResource;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The merchant's own rate change (PLAN §7 rate rules, §4 tiers):
 *
 *  - INCREASES apply immediately: the current merchant_rates row is closed
 *    at now and a new open-ended row starts at now. A till quoting the old
 *    (lower) rate can only under-promise.
 *  - DECREASES apply at the NEXT 00:00 in the business timezone: the
 *    current row's effective_to is set to that boundary and the new row
 *    starts there. This guarantees a stale till cache can never
 *    over-promise — the advertised rate is honoured until midnight.
 *  - A new change REPLACES any not-yet-applied scheduled row (the pending
 *    decrease): the unapplied future row is deleted and the current row
 *    reopened before the new rule is applied. Deleting a row that was never
 *    effective does not break the append-only history — no transaction ever
 *    resolved against it.
 *
 * Sale-time resolution (CreditRecorder::resolveRateBp) reads this history
 * at occurred_at, so both rules take effect for exactly the sales they
 * should: an increase from the moment of the change, a decrease from the
 * first second of the next business day.
 *
 * Only the merchant OWNER may change the rate — it reprices every future
 * sale and moves the platform fee tier (§4).
 */
class RateController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $now = CarbonImmutable::now('UTC');

        return new JsonResponse([
            'data' => [
                'current' => RateResource::forRate($this->currentRate($merchant, $now)),
                'pending' => RateResource::forRate($this->pendingRate($merchant, $now)),
            ],
        ]);
    }

    public function store(Request $request, WebhookDispatcher $webhooks): JsonResponse
    {
        $merchant = $this->merchant($request);
        $user = $request->user();

        // Owner role only: staff can read the rate, never reprice the shop.
        if (! $user instanceof MerchantUser || $user->role !== 'owner') {
            abort(403, 'Only the merchant owner can change the cashback rate.');
        }

        // §4: integer basis points 50–1000, or 4.995% falls into no tier.
        $validated = $request->validate([
            'rate_bp' => ['required', 'integer', 'min:50', 'max:1000'],
        ]);

        $rateBp = (int) $validated['rate_bp'];
        $now = CarbonImmutable::now('UTC');

        $change = DB::transaction(function () use ($merchant, $user, $rateBp, $now): array {
            // Serialise concurrent changes on this merchant's history.
            $rows = MerchantRate::query()
                ->where('merchant_id', $merchant->id)
                ->lockForUpdate()
                ->orderByDesc('effective_from')
                ->get();

            // Any scheduled-but-unapplied row is replaced by this change.
            $pendingReplaced = false;

            foreach ($rows->filter(fn (MerchantRate $row) => $row->effective_from->isAfter($now)) as $pending) {
                $pending->delete();
                $pendingReplaced = true;
            }

            $current = $rows->first(fn (MerchantRate $row) => $row->effective_from->lessThanOrEqualTo($now)
                && ($row->effective_to === null || $row->effective_to->isAfter($now)));

            // Reopen the current row if it was closed toward a boundary the
            // deleted pending row owned.
            if ($current !== null && $current->effective_to !== null && $current->effective_to->isAfter($now)) {
                $current->update(['effective_to' => null]);
            }

            $previousRateBp = $current?->rate_bp;

            if ($current === null || $rateBp > $current->rate_bp) {
                // First rate ever, or an increase: effective immediately.
                $effectiveAt = $now;
                $current?->update(['effective_to' => $now]);
                $this->insertRate($merchant, $user, $rateBp, $effectiveAt);
            } elseif ($rateBp < $current->rate_bp) {
                // Decrease: effective at the next business-day midnight.
                $effectiveAt = $this->nextBusinessMidnight($now);
                $current->update(['effective_to' => $effectiveAt]);
                $this->insertRate($merchant, $user, $rateBp, $effectiveAt);
            } else {
                // Same rate as current: nothing to apply. If a pending row
                // was cancelled, that alone changed the future — report it.
                $effectiveAt = $now;
            }

            return [
                'previous_rate_bp' => $previousRateBp,
                'effective_at' => $effectiveAt,
                'applied' => $current === null || $rateBp !== $current->rate_bp,
                'pending_replaced' => $pendingReplaced,
            ];
        });

        // Emit AFTER commit — the queued job reads the delivery row, which
        // must be visible to the queue worker. A same-rate no-op still
        // notifies when it cancelled a scheduled change (tills hold a
        // pending_decrease that is no longer real).
        if ($change['applied'] || $change['pending_replaced']) {
            $webhooks->dispatch(WebhookEvents::MERCHANT_RATE_CHANGED, [
                'merchant_id' => $merchant->id,
                'rate_bp' => $rateBp,
                'fee_bp' => FeeTier::feeBpFor($rateBp),
                'previous_rate_bp' => $change['previous_rate_bp'] ?? $rateBp,
                'previous_fee_bp' => FeeTier::feeBpFor($change['previous_rate_bp'] ?? $rateBp),
                'effective_at' => $change['effective_at']
                    ->setTimezone($this->businessTimezone())
                    ->toIso8601String(),
            ]);
        }

        $previousRateBp = $change['previous_rate_bp'] ?? $rateBp;

        return new JsonResponse([
            'data' => [
                'current' => RateResource::forRate($this->currentRate($merchant, CarbonImmutable::now('UTC'))),
                'pending' => RateResource::forRate($this->pendingRate($merchant, CarbonImmutable::now('UTC'))),
            ],
            // §4 tier-cliff data: fee before/after and all-in cost, so the
            // panel can warn (e.g. 499 → 500: +0.01pp cashback, +0.26pp
            // all-in).
            'change' => [
                'previous' => RateResource::describeBp($previousRateBp),
                'new' => RateResource::describeBp($rateBp),
                'effective_at' => $change['effective_at']->setTimezone($this->businessTimezone())->toIso8601String(),
                'applies' => $change['effective_at']->isAfter(CarbonImmutable::now('UTC'))
                    ? 'next_business_midnight'
                    : 'immediately',
                'tier_changed' => FeeTier::feeBpFor($previousRateBp) !== FeeTier::feeBpFor($rateBp),
            ],
        ]);
    }

    private function currentRate(Merchant $merchant, CarbonImmutable $now): ?MerchantRate
    {
        return MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function pendingRate(Merchant $merchant, CarbonImmutable $now): ?MerchantRate
    {
        return MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '>', $now)
            ->orderBy('effective_from')
            ->first();
    }

    private function insertRate(Merchant $merchant, MerchantUser $user, int $rateBp, CarbonImmutable $effectiveFrom): void
    {
        MerchantRate::query()->create([
            'merchant_id' => $merchant->id,
            'rate_bp' => $rateBp,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * §7: rate decreases take effect only at 00:00 next day, business
     * timezone — stored in UTC like every other timestamp.
     */
    private function nextBusinessMidnight(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone($this->businessTimezone())->addDay()->startOfDay()->utc();
    }

    private function businessTimezone(): string
    {
        return (string) config('app.business_timezone', 'Indian/Maldives');
    }

    private function merchant(Request $request): Merchant
    {
        /** @var MerchantUser $user */
        $user = $request->user();

        return $user->merchant;
    }
}
