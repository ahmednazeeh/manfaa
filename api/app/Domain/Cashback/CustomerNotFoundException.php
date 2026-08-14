<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use DomainException;

final class CustomerNotFoundException extends DomainException
{
    public static function forCode(string $customerCode): self
    {
        return new self(sprintf('No customer found for code %s.', $customerCode));
    }
}
