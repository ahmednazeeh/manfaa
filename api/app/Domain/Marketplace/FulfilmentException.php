<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use DomainException;

final class FulfilmentException extends DomainException
{
    public static function cannotMove(string $from, string $to): self
    {
        return new self(sprintf('An order that is %s cannot become %s.', $from, $to));
    }
}
