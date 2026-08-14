<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use DomainException;

/**
 * The signup token is unknown, already redeemed, or expired.
 */
final class InvalidSignupTokenException extends DomainException
{
    public static function make(): self
    {
        return new self('The signup token is invalid or has expired.');
    }
}
