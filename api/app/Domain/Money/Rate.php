<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;
use OutOfRangeException;

/**
 * An integer count of basis points (2% = 200). The int-typed factories are
 * the only way in, so non-integers are rejected by the type system.
 */
final readonly class Rate
{
    private function __construct(private int $basisPoints) {}

    /**
     * Customer cashback rates: 50–1000 bp inclusive, per the §4 tier table.
     */
    public static function cashback(int $basisPoints): self
    {
        if ($basisPoints < 50 || $basisPoints > 1000) {
            throw new OutOfRangeException(
                sprintf('Cashback rate must be 50-1000 basis points, got %d.', $basisPoints)
            );
        }

        return new self($basisPoints);
    }

    /**
     * Platform fee rates: exactly one of the four tier values.
     */
    public static function fee(int $basisPoints): self
    {
        if (! in_array($basisPoints, [25, 50, 75, 100], true)) {
            throw new InvalidArgumentException(
                sprintf('Fee rate must be one of 25, 50, 75, 100 basis points, got %d.', $basisPoints)
            );
        }

        return new self($basisPoints);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }
}
