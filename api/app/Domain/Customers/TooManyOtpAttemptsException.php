<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use DomainException;

/**
 * The live code has burned its 5 attempts — even the correct code is now
 * refused. The customer must request a fresh code.
 */
final class TooManyOtpAttemptsException extends DomainException
{
    public static function forPhone(string $phone): self
    {
        return new self(sprintf('Verification attempts exhausted for %s.', $phone));
    }
}
