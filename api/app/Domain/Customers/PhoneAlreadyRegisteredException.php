<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use DomainException;

/**
 * Thrown at register time only — after OTP possession has proven the caller
 * controls the phone, so telling them it already has an account reveals
 * nothing to an outsider (the request-otp and verify-otp steps stay
 * enumeration-silent).
 */
final class PhoneAlreadyRegisteredException extends DomainException
{
    public static function forPhone(string $phone): self
    {
        return new self(sprintf('%s already has an account.', $phone));
    }
}
