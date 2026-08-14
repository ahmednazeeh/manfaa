<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Domain\Money\FeeTier;
use App\Domain\Money\TierSchedule;
use App\Models\FeeTierSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use OutOfRangeException;

/**
 * Resolves the fee tier schedule active at an instant: the latest
 * fee_tier_schedules row with effective_from <= T. The migration seeds the
 * hardcoded §4 table (50-1000 bp coverage, kept as-is under the append-only
 * law) effective from the far past, so every instant resolves; a null
 * answer (empty or not-yet-migrated table) tells the caller to fall back to
 * the static FeeTier map, which covers the full structural 50-2000 bp range.
 *
 * A rate above the resolved schedule's own ceiling is NOT priced (feeBpFor
 * throws) — rate-setting paths guarantee such rates are never set while
 * that schedule is active (TierScheduleService::activeCeiling()).
 */
final class FeeTierScheduleResolver
{
    private ?bool $tableExists = null;

    public function at(CarbonImmutable $instant): ?TierSchedule
    {
        // Deploy-order safety: code can reach production before the
        // fee_tier_schedules migration runs. Probed with hasTable rather
        // than by catching QueryException — this resolver runs INSIDE the
        // credit DB transaction, and a failed query would abort it.
        if (! ($this->tableExists ??= Schema::hasTable('fee_tier_schedules'))) {
            return null;
        }

        $row = FeeTierSchedule::query()
            ->where('effective_from', '<=', $instant->utc())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return TierSchedule::fromArray($row->tiers);
    }

    /**
     * The fee_bp a cashback rate is billed at the given instant — for every
     * DISPLAY surface (panel rates, till rate endpoint, rate-change webhook
     * payloads). Must resolve from the same schedule the billing path
     * (TermsResolver) prices under, never the static §4 map alone: once a
     * published schedule diverges, the static map would quote one fee while
     * credits freeze another.
     */
    public function feeBpAt(int $cashbackBp, CarbonImmutable $instant): int
    {
        return $this->at($instant)?->feeBpFor($cashbackBp) ?? FeeTier::feeBpFor($cashbackBp);
    }

    /**
     * feeBpAt for surfaces that must SURVIVE an unpriced rate: null instead
     * of the throw. The coverage invariant (TierScheduleService) means an
     * in-force rate is normally always priced — but the merchant's own
     * rate-decrease is the self-rescue from a legacy stranded state, and it
     * must not 500 after committing just because the PREVIOUS rate has no
     * fee under the schedule then in force.
     */
    public function tryFeeBpAt(int $cashbackBp, CarbonImmutable $instant): ?int
    {
        try {
            return $this->feeBpAt($cashbackBp, $instant);
        } catch (OutOfRangeException) {
            return null;
        }
    }
}
