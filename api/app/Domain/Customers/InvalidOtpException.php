<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use DomainException;

/**
 * The code did not match, has expired, or no live code exists for the phone.
 * One exception for all three on purpose — the response must not reveal
 * which, or it becomes an oracle for guessing.
 */
final class InvalidOtpException extends DomainException
{
    public static function forPhone(string $phone): self
    {
        return new self(sprintf('No valid verification code for %s.', $phone));
    }
}
