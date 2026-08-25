<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;
use OutOfRangeException;

/**
 * One effective-dated fee tier table (§4): an ordered list of
 * {from_bp, to_bp, fee_bp} bands. Valid schedules are contiguous,
 * non-overlapping, ascending, start at exactly 50 bp, and every band's
 * fee_bp is a positive integer no greater than its from_bp — the platform
 * fee can never exceed the cashback rate it is charged on.
 *
 * The schedule's CEILING is its own last band's to_bp (any value up to the
 * absolute 2000 bp / 20% structural maximum) — coverage no longer has to
 * end at a fixed bound. Rates above a schedule's ceiling are NOT PRICED
 * under it: feeBpFor() throws, and rate-setting paths refuse them with
 * `rate_not_priced` until an admin publishes a wider table
 * (TierScheduleService::activeCeiling()). The historical seeded row keeps
 * its 50-1000 coverage untouched (append-only law) and remains valid.
 *
 * feeBpFor() keeps the exact OutOfRange semantics of the static FeeTier
 * map, which remains the hardcoded fallback when no schedule row exists.
 */
final readonly class TierSchedule
{
    private const int RANGE_MIN_BP = 50;

    /**
     * Absolute maximum any band may reach — the §4 structural cashback
     * ceiling (20%). A schedule may end below this, never above it.
     */
    private const int ABSOLUTE_MAX_BP = Rate::MAX_CASHBACK_BP;

    /**
     * @param  list<array{from_bp: int, to_bp: int, fee_bp: int}>  $tiers
     */
    private function __construct(private array $tiers) {}

    /**
     * Validates and wraps a tier array (see the class doc for the rules).
     * Throws InvalidArgumentException naming the first violated rule.
     *
     * @param  array<int, mixed>  $tiers
     */
    public static function fromArray(array $tiers): self
    {
        if ($tiers === [] || ! array_is_list($tiers)) {
            throw new InvalidArgumentException('Tiers must be a non-empty list.');
        }

        $normalized = [];
        $expectedFrom = self::RANGE_MIN_BP;

        foreach ($tiers as $index => $tier) {
            if (! is_array($tier)) {
                throw new InvalidArgumentException(sprintf('Tier %d must be an object with from_bp, to_bp and fee_bp.', $index));
            }

            foreach (['from_bp', 'to_bp', 'fee_bp'] as $field) {
                if (! isset($tier[$field]) || ! is_int($tier[$field])) {
                    throw new InvalidArgumentException(sprintf('Tier %d: %s must be an integer.', $index, $field));
                }
            }

            $from = $tier['from_bp'];
            $to = $tier['to_bp'];
            $fee = $tier['fee_bp'];

            if ($from > $to) {
                throw new InvalidArgumentException(sprintf('Tier %d: from_bp %d exceeds to_bp %d.', $index, $from, $to));
            }

            if ($from !== $expectedFrom) {
                throw new InvalidArgumentException(sprintf(
                    'Tier %d: expected from_bp %d, got %d — tiers must be ascending, contiguous from exactly %d bp with no gaps or overlaps.',
                    $index,
                    $expectedFrom,
                    $from,
                    self::RANGE_MIN_BP,
                ));
            }

            if ($to > self::ABSOLUTE_MAX_BP) {
                throw new InvalidArgumentException(sprintf('Tier %d: to_bp %d exceeds the absolute %d bp ceiling.', $index, $to, self::ABSOLUTE_MAX_BP));
            }

            if ($fee < 1) {
                throw new InvalidArgumentException(sprintf('Tier %d: fee_bp must be a positive integer, got %d.', $index, $fee));
            }

            if ($fee > $from) {
                throw new InvalidArgumentException(sprintf(
                    'Tier %d: fee_bp %d exceeds from_bp %d — the fee may never exceed the cashback rate.',
                    $index,
                    $fee,
                    $from,
                ));
            }

            $normalized[] = ['from_bp' => $from, 'to_bp' => $to, 'fee_bp' => $fee];
            $expectedFrom = $to + 1;
        }

        return new self($normalized);
    }

    /**
     * The hardcoded §4 tier table — identical to the static FeeTier map
     * (top band runs to the absolute 2000 bp ceiling).
     */
    public static function default(): self
    {
        return self::fromArray([
            ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 25],
            ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 50],
            ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 75],
            ['from_bp' => 500, 'to_bp' => 2000, 'fee_bp' => 100],
        ]);
    }

    /**
     * The highest cashback rate this schedule prices — its last band's
     * to_bp. Rates above it are unsellable under this schedule.
     */
    public function ceilingBp(): int
    {
        return $this->tiers[count($this->tiers) - 1]['to_bp'];
    }

    /**
     * The LOWEST platform fee this schedule charges anybody — its cheapest
     * band. The bound a platform fee PROMOTION is checked against
     * (FeePromotionsController, owner 2026-08-25): a promotional fee above
     * it would make some merchant — whoever sits on that cheapest band —
     * pay MORE than their ordinary tier, and a promotion that costs the
     * merchant more is a mistake, not a promotion.
     */
    public function cheapestFeeBp(): int
    {
        return min(array_column($this->tiers, 'fee_bp'));
    }

    public function feeBpFor(int $cashbackBp): int
    {
        foreach ($this->tiers as $tier) {
            if ($cashbackBp >= $tier['from_bp'] && $cashbackBp <= $tier['to_bp']) {
                return $tier['fee_bp'];
            }
        }

        throw new OutOfRangeException(
            sprintf('No fee tier for %d basis points; this schedule prices 50-%d.', $cashbackBp, $this->ceilingBp())
        );
    }

    /**
     * @return list<array{from_bp: int, to_bp: int, fee_bp: int}>
     */
    public function toArray(): array
    {
        return $this->tiers;
    }
}
