<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use DomainException;

/**
 * A refused platform-bank-account change.
 */
final class BankAccountException extends DomainException
{
    public static function immutableAccountNo(): self
    {
        return new self(
            'The account number cannot be changed on an existing account — '
            .'old settlement instructions must stay explicable. '
            .'Add the new account and deactivate this one instead.'
        );
    }
}
