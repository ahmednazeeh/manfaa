<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use DomainException;

/**
 * Thrown at register time only — after OTP possession has proven the caller
 * controls a phone, so the earlier steps stay enumeration-silent. The email
 * must be unique across ALL merchant panel accounts (merchant_users.email
 * carries a unique index); this is the friendly pre-check in front of it.
 */
final class EmailAlreadyRegisteredException extends DomainException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('%s already has a merchant panel account.', $email));
    }
}
