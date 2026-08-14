<?php

declare(strict_types=1);

namespace App\Domain\MerchantSettings;

use DomainException;

/**
 * A refused merchant staff change: self-targeting (you can neither demote
 * nor deactivate yourself) or removing the merchant's last active owner.
 */
final class StaffException extends DomainException
{
    public static function cannotDemoteSelf(): self
    {
        return new self('You cannot demote yourself.');
    }

    public static function cannotDeactivateSelf(): self
    {
        return new self('You cannot deactivate yourself.');
    }

    public static function lastActiveOwner(): self
    {
        return new self('This is the merchant\'s last active owner — it can be neither deactivated nor demoted.');
    }
}
