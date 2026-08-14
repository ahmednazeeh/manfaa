<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\Merchant;
use Carbon\CarbonImmutable;
use DomainException;

final class NoEffectiveRateException extends DomainException
{
    public static function for(Merchant $merchant, CarbonImmutable $occurredAt): self
    {
        return new self(sprintf(
            'Merchant %s has no cashback rate effective at %s.',
            $merchant->name,
            $occurredAt->toIso8601String(),
        ));
    }
}
