<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Models\ApiCredential;
use DomainException;

final class CredentialAlreadyRevokedException extends DomainException
{
    public static function for(ApiCredential $credential): self
    {
        return new self(sprintf(
            'Credential #%d was already revoked at %s.',
            $credential->getKey(),
            $credential->revoked_at?->toIso8601String() ?? 'unknown',
        ));
    }
}
