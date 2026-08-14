<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

final class Assert
{
    /**
     * Sanity bound well inside PostgreSQL bigint: |v| <= 90 billion MVR in laari.
     */
    public const int MAX_ABS_LAARI = 9_000_000_000_000;

    public static function laariRange(int $value): int
    {
        if ($value > self::MAX_ABS_LAARI || $value < -self::MAX_ABS_LAARI) {
            throw new InvalidArgumentException(
                sprintf('Laari amount %d exceeds the sanity bound of ±%d.', $value, self::MAX_ABS_LAARI)
            );
        }

        return $value;
    }
}
