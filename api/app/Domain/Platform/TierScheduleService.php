<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Domain\Money\FeeTier;
use App\Domain\Money\TierSchedule;
use App\Models\AdminUser;
use App\Models\FeeTierSchedule;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin management of the append-only fee tier schedule history. Creation
 * validates the tier table (TierSchedule::fromArray) and requires
 * effective_from at least one hour in the future; rows are never updated or
 * deleted, so every historical instant keeps resolving to the terms it was
 * priced under.
 *
 * COVERAGE INVARIANT (both directions, enforced under one advisory lock):
 * at every instant, every in-force standing rate and published promotion
 * rate is priced by the schedule in force at that instant. Rate-setting
 * paths refuse rates any current-or-future schedule does not price
 * (assertPricedThrough), and create() refuses a schedule whose ceiling is
 * below a rate already sold for its coverage window — otherwise every
 * credit for that rate would throw at billing time (TermsResolver), and
 * a published promotion has no cancel path to rescue it.
 */
final class TierScheduleService
{
    private const int MINIMUM_LEAD_TIME_MINUTES = 60;

    /**
     * Single bigint-form advisory lock key serialising every writer of the
     * coverage invariant: schedule creation, standing rate changes and
     * promotion publishing. Transaction-scoped (releases at commit or
     * rollback); the bigint form is a separate lock space from the two-int
     * form TermsResolver uses for promo caps, so they can never collide.
     */
    private const int COVERAGE_LOCK_KEY = 20260814;

    /**
     * Take the coverage lock inside the caller's DB transaction. MUST be
     * held before validating a rate against schedule coverage or a schedule
     * against in-force rates — two concurrent writers validating against
     * each other's uncommitted state is classic write skew.
     */
    public static function lockCoverage(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?::bigint)', [self::COVERAGE_LOCK_KEY]);
    }

    /**
     * @param  array<int, mixed>  $tiers
     *
     * @throws InvalidTierScheduleException|\InvalidArgumentException
     */
    public function create(array $tiers, CarbonImmutable $effectiveFrom, AdminUser $creator): FeeTierSchedule
    {
        $schedule = TierSchedule::fromArray($tiers);

        $now = CarbonImmutable::now('UTC');

        if ($effectiveFrom->utc()->lt($now->addMinutes(self::MINIMUM_LEAD_TIME_MINUTES))) {
            throw InvalidTierScheduleException::effectiveFromTooSoon();
        }

        return DB::transaction(function () use ($schedule, $effectiveFrom, $creator, $now): FeeTierSchedule {
            self::lockCoverage();

            $this->assertCoversInForceRates($schedule, $effectiveFrom->utc());

            return FeeTierSchedule::query()->create([
                'effective_from' => $effectiveFrom->utc(),
                'tiers' => $schedule->toArray(),
                'created_by' => $creator->id,
                'created_at' => $now,
            ]);
        });
    }

    /**
     * The guard against stranding already-sold rates: the new schedule's
     * ceiling must cover every standing rate and every PUBLISHED promotion
     * in force at any instant of the schedule's own coverage window —
     * [effective_from, the next later schedule's effective_from). A rate
     * only in force after an already-published later schedule takes over is
     * that schedule's concern, not this one's. Draft promotions do not
     * count (publish re-validates them); closed merchants do not count
     * (closure is terminal — their rates never price another credit), but
     * suspended ones do (reinstatement requires no rate re-validation).
     *
     * Without this, a live rate above the new ceiling makes every credit
     * for that merchant throw at billing time (HTTP 500 on the POS POST,
     * inside the credit transaction), and a published promotion above it
     * cannot even be cancelled — PLAN §7 offers no early end.
     *
     * @throws InvalidTierScheduleException
     */
    private function assertCoversInForceRates(TierSchedule $schedule, CarbonImmutable $from): void
    {
        $ceilingBp = $schedule->ceilingBp();

        $until = FeeTierSchedule::query()
            ->where('effective_from', '>', $from)
            ->min('effective_from');
        $until = $until === null ? null : CarbonImmutable::parse((string) $until);

        $notClosed = Merchant::query()->where('status', '!=', 'closed')->select('id');

        $maxStandingBp = (int) MerchantRate::query()
            ->whereIn('merchant_id', $notClosed)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
            ->max('rate_bp');

        $maxPromoBp = (int) Promotion::query()
            ->whereIn('merchant_id', $notClosed)
            ->where('status', 'published')
            ->where('ends_at', '>', $from)
            ->when($until !== null, fn ($query) => $query->where('starts_at', '<', $until))
            ->max('rate_bp');

        $maxInForceBp = max($maxStandingBp, $maxPromoBp);

        if ($maxInForceBp > $ceilingBp) {
            throw InvalidTierScheduleException::ceilingBelowInForceRates($ceilingBp, $maxInForceBp);
        }
    }

    /**
     * The schedule row active right now.
     */
    public function current(): ?FeeTierSchedule
    {
        return FeeTierSchedule::query()
            ->where('effective_from', '<=', CarbonImmutable::now('UTC'))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The highest cashback rate the ACTIVE schedule prices — its last
     * band's to_bp. With no schedule row at all (empty or not-yet-migrated
     * table) the static §4 fallback map's 2000 bp ceiling applies.
     *
     * Rates above this ceiling are UNSELLABLE (rate-setting paths reject
     * them with `rate_not_priced`) until an admin publishes a wider table
     * and it takes effect. That is intended: the platform fee must be
     * priced before a rate can be sold. In particular, while the seeded
     * 50-1000 schedule is still the active row, merchants cannot set rates
     * above 10% even though the structural cap is 20% — the seed row is
     * never rewritten (append-only law); the admin extends coverage by
     * publishing a new schedule.
     */
    public function activeCeiling(): int
    {
        $row = $this->current();

        if ($row === null) {
            return FeeTier::CEILING_BP;
        }

        return TierSchedule::fromArray($row->tiers)->ceilingBp();
    }

    /**
     * Refuses a cashback rate the active schedule does not price.
     *
     * @throws RateNotPricedException
     */
    public function assertPriced(int $rateBp): void
    {
        $ceilingBp = $this->activeCeiling();

        if ($rateBp > $ceilingBp) {
            throw RateNotPricedException::above($ceilingBp);
        }
    }

    /**
     * Refuses a cashback rate that any schedule GOVERNING THE INSTANTS THE
     * RATE WILL BE IN FORCE does not price: the schedule in force at $from,
     * every already-published schedule taking effect inside [$from, $until),
     * and — conservatively, matching assertPriced's documented no-early-
     * unlock rule — the schedule active right now. $until null means the
     * rate is open-ended (a standing rate), so every future schedule counts.
     *
     * Validating against the active schedule alone is not enough: a
     * narrower schedule already published and visible in the DB passes that
     * check and then strands the rate the moment it takes effect (every
     * credit throws at billing time). Callers that WRITE a rate must hold
     * lockCoverage() in the same transaction, or a concurrently created
     * schedule can slip past both this check and create()'s guard.
     *
     * @throws RateNotPricedException
     */
    public function assertPricedThrough(int $rateBp, CarbonImmutable $from, ?CarbonImmutable $until = null): void
    {
        $ceilingBp = min($this->activeCeiling(), $this->ceilingThrough($from->utc(), $until?->utc()));

        if ($rateBp > $ceilingBp) {
            throw RateNotPricedException::above($ceilingBp);
        }
    }

    /**
     * The narrowest ceiling among the schedules governing any instant of
     * [$from, $until) — the row in force at $from plus every row taking
     * effect before $until ($until null: every later row). No rows at all
     * falls back to the static §4 map's structural ceiling. Two rows
     * sharing an effective_from both count (the shadowed one merely makes
     * this conservative, never permissive).
     */
    private function ceilingThrough(CarbonImmutable $from, ?CarbonImmutable $until): int
    {
        $atFrom = FeeTierSchedule::query()
            ->where('effective_from', '<=', $from)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        $rows = FeeTierSchedule::query()
            ->where('effective_from', '>', $from)
            ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
            ->get()
            ->when($atFrom !== null, fn ($collection) => $collection->push($atFrom));

        if ($rows->isEmpty()) {
            return FeeTier::CEILING_BP;
        }

        return $rows
            ->map(fn (FeeTierSchedule $row): int => TierSchedule::fromArray($row->tiers)->ceilingBp())
            ->min();
    }

    /**
     * Full history, newest effective date first.
     *
     * @return Collection<int, FeeTierSchedule>
     */
    public function history(): Collection
    {
        return FeeTierSchedule::query()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }
}
