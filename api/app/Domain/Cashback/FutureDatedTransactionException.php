<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use Carbon\CarbonImmutable;
use DomainException;

final class FutureDatedTransactionException extends DomainException
{
    public static function at(CarbonImmutable $occurredAt): self
    {
        return new self(sprintf(
            'occurred_at %s is in the future — future-dated sales are rejected.',
            $occurredAt->toIso8601String(),
        ));
    }
}
