<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use RuntimeException;

/**
 * A cashback rate above the ACTIVE fee tier schedule's ceiling was refused
 * (error code `rate_not_priced`): the platform fee for that rate is not
 * priced anywhere, so the rate is not sellable. This is intended behaviour,
 * not a gap — the fee must be priced before a rate can be sold. The rate
 * becomes settable the moment an admin publishes a wider schedule and it
 * takes effect (TierScheduleService::activeCeiling()).
 */
final class RateNotPricedException extends RuntimeException
{
    public const string CODE = 'rate_not_priced';

    private function __construct(string $message, private readonly int $ceilingBp)
    {
        parent::__construct($message);
    }

    public static function above(int $ceilingBp): self
    {
        return new self(
            sprintf('The current fee schedule prices rates up to %s%%.', self::percent($ceilingBp)),
            $ceilingBp,
        );
    }

    public function ceilingBp(): int
    {
        return $this->ceilingBp;
    }

    /**
     * Exact 2dp percent from integer basis points — pure integer math,
     * never floats (money law): 1000 -> "10.00", 2000 -> "20.00".
     */
    private static function percent(int $bp): string
    {
        return sprintf('%d.%02d', intdiv($bp, 100), $bp % 100);
    }
}
