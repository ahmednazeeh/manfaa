<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Discovery\DiscoveryService;
use App\Domain\MerchantAccess\Permission;
use App\Domain\Money\Percent;
use App\Domain\Platform\FeeTierScheduleResolver;
use App\Domain\Platform\RateNotPricedException;
use App\Domain\Platform\TierScheduleService;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Domain\Webhooks\WebhookEvents;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMerchantPermission;
use App\Http\Resources\RateResource;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Rules\PercentRate;
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

    public function store(Request $request, WebhookDispatcher $webhooks, FeeTierScheduleResolver $feeTiers, TierScheduleService $schedules): JsonResponse
    {
        $merchant = $this->merchant($request);
        $user = $request->user();

        // Belt-and-braces behind the merchant.can:rate.update route gate,
        // answering with the same body so the panel has one refusal to
        // handle: reading the rate and repricing the shop are different
        // permissions, and this is the one endpoint where getting that
        // wrong moves what every till advertises.
        if (! $user instanceof MerchantUser || ! $user->can(Permission::RateUpdate)) {
            abort(EnsureMerchantPermission::deny(Permission::RateUpdate));
        }

        // PLAN §1 wire format: the rate arrives as a 2-decimal percent —
        // "2", "2.5", "2.50" or the JSON number 2.5 — and is converted to
        // integer basis points here, once, by exact integer math. §4 bounds
        // (0.50%–20.00%, the structural cap) are the rule's own; a finer
        // value than 0.01pp is refused, or 4.995% would fall into no tier.
        $validated = $request->validate([
            'cashback_rate_percent' => ['required', PercentRate::cashback()],
        ]);

        $rateBp = Percent::toBasisPoints($validated['cashback_rate_percent']);

        $now = CarbonImmutable::now('UTC');

        try {
            $change = DB::transaction(function () use ($merchant, $user, $rateBp, $now, $schedules): array {
                // Coverage lock first (see TierScheduleService): a schedule
                // created concurrently must either be visible to the check
                // below or refuse itself against this committed rate row.
                TierScheduleService::lockCoverage();

                // A rate no current-or-future fee tier schedule prices is
                // unsellable — the fee must be priced before the rate can be
                // sold. Checked from NOW onward because the new standing rate
                // is open-ended: the active schedule (conservative — a wider
                // schedule published but not yet effective unlocks nothing
                // early) AND every schedule already scheduled to take effect
                // later. Validating only the active one would let a rate
                // strand the moment an already-published narrower schedule
                // takes effect, and 500 every credit from then on.
                $schedules->assertPricedThrough($rateBp, $now);

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
        } catch (RateNotPricedException $e) {
            // Thrown inside the transaction, so NOTHING was committed — the
            // refusal must never leave a half-applied change behind.
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => RateNotPricedException::CODE,
            ], 422);
        }

        // Fees resolve from the admin-managed schedule AT the instant the
        // change takes effect — the same source billing prices from. The
        // static §4 map would lie the moment a published schedule diverges.
        // Resolved BEFORE anything is returned but AFTER commit; the NEW
        // rate is guaranteed priced (validated through every schedule
        // above), while the PREVIOUS rate may be a legacy stranded one with
        // no fee under the schedule at effective_at — this very change is
        // the merchant's self-rescue, so it must survive that (null fee),
        // never 500 after committing and never skip the webhook tills rely
        // on for the §7 pending-decrease display.
        $effectiveAt = $change['effective_at'];
        $previousRateBp = $change['previous_rate_bp'] ?? $rateBp;
        $newFeeBp = $feeTiers->feeBpAt($rateBp, $effectiveAt);
        $previousFeeBp = $feeTiers->tryFeeBpAt($previousRateBp, $effectiveAt);

        // Emit AFTER commit — the queued job reads the delivery row, which
        // must be visible to the queue worker. A same-rate no-op still
        // notifies when it cancelled a scheduled change (tills hold a
        // pending_decrease that is no longer real).
        // previous_platform_fee_percent is null when the outgoing rate was
        // never priced by the schedule at effective_at (legacy stranded
        // rate being rescued).
        if ($change['applied'] || $change['pending_replaced']) {
            $webhooks->dispatch(WebhookEvents::MERCHANT_RATE_CHANGED, [
                'merchant_id' => $merchant->id,
                'cashback_rate_percent' => Percent::format($rateBp),
                'platform_fee_percent' => Percent::format($newFeeBp),
                'previous_cashback_rate_percent' => Percent::format($previousRateBp),
                'previous_platform_fee_percent' => Percent::formatOrNull($previousFeeBp),
                'effective_at' => $effectiveAt
                    ->setTimezone($this->businessTimezone())
                    ->toIso8601String(),
            ]);
        }

        // The standing rate is the headline number on the public card and
        // store page, both read models cached for 60s. An INCREASE applies
        // immediately, so without this the storefront would keep quoting the
        // old percentage for up to a minute after the merchant raised it
        // (DiscoveryService::forgetMerchant).
        DiscoveryService::forgetMerchant($merchant);

        return new JsonResponse([
            'data' => [
                'current' => RateResource::forRate($this->currentRate($merchant, CarbonImmutable::now('UTC'))),
                'pending' => RateResource::forRate($this->pendingRate($merchant, CarbonImmutable::now('UTC'))),
            ],
            // §4 tier-cliff data: fee before/after and all-in cost, so the
            // panel can warn (e.g. 499 → 500: +0.01pp cashback, +0.26pp
            // all-in). Both sides priced under the schedule at effective_at,
            // so the warning fires on the ACTUAL fee boundaries in force.
            // previous carries null fee fields when the outgoing rate was
            // unpriced (stranded) at effective_at.
            'change' => [
                'previous' => RateResource::tryDescribe($previousRateBp, $effectiveAt),
                'new' => RateResource::describe($rateBp, $effectiveAt),
                'effective_at' => $effectiveAt->setTimezone($this->businessTimezone())->toIso8601String(),
                'applies' => $effectiveAt->isAfter(CarbonImmutable::now('UTC'))
                    ? 'next_business_midnight'
                    : 'immediately',
                'tier_changed' => $previousFeeBp !== $newFeeBp,
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
