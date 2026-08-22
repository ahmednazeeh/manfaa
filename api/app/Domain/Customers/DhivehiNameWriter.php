<?php

declare(strict_types=1);

namespace App\Domain\Customers;

/**
 * Writes a personal name in Thaana (owner, 2026-08-21).
 *
 * An interface for the same reason {@see \App\Domain\Push\PushSender} is one:
 * the real implementation talks to a paid API over the network, and the rules
 * around it — never overwrite a correction, a failure is harmless — are worth
 * testing without either.
 */
interface DhivehiNameWriter
{
    /** The name in Thaana, or null when it could not be written. */
    public function write(string $englishName): ?string;
}
