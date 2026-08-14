<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use DomainException;

/**
 * A refused admin-account change: self-targeting (you can neither demote nor
 * deactivate yourself) or removing the last active superadmin.
 */
final class AdminAccountException extends DomainException
{
    public static function cannotDemoteSelf(): self
    {
        return new self('You cannot demote yourself.');
    }

    public static function cannotDeactivateSelf(): self
    {
        return new self('You cannot deactivate yourself.');
    }

    public static function lastActiveSuperadmin(): self
    {
        return new self('This is the last active superadmin — it can be neither deactivated nor demoted.');
    }
}
