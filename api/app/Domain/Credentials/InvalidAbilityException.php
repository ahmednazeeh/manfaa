<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use DomainException;

final class InvalidAbilityException extends DomainException
{
    public static function unknown(string $ability): self
    {
        return new self(sprintf(
            'Unknown ability "%s" — valid abilities are %s.',
            $ability,
            implode(', ', VendorAbility::values()),
        ));
    }

    public static function empty(): self
    {
        return new self('A credential must carry at least one ability.');
    }
}
