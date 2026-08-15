<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use RuntimeException;

/**
 * The slip passed inspection but could not be written to the private disk —
 * ENOSPC, a quota, or a permissions change under storage/app/slips.
 *
 * Deliberately NOT a DomainException: the merchant did nothing wrong and
 * there is nothing for them to correct, so this must surface as a 5xx that
 * gets reported, never as a 4xx the panel would render as the merchant's
 * mistake. It is thrown from inside the settlement's DB transaction so the
 * whole submission rolls back: PLAN §1 says no settlement exists without a
 * receipt, and a payment row pointing at a file that was never written is
 * exactly that settlement.
 */
final class SlipWriteFailedException extends RuntimeException
{
    public static function at(string $path): self
    {
        return new self(sprintf(
            'The payment slip could not be written to the %s disk at "%s" — the settlement was not created.',
            SlipStorage::DISK,
            $path,
        ));
    }
}
