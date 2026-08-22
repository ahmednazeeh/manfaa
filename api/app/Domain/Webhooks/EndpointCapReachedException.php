<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use DomainException;

final class EndpointCapReachedException extends DomainException
{
    public static function at(int $cap): self
    {
        return new self(sprintf(
            'This store already has %d active webhook endpoints, the maximum. Remove one you no longer use first.',
            $cap,
        ));
    }
}
