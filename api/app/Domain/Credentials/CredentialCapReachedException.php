<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use DomainException;

/**
 * The per-merchant ceiling on live vendor tokens (§13b task #21). Self-serve
 * issuance is unattended, so the blast radius of a compromised owner login
 * is bounded by a cap rather than by our review: a merchant may hold at most
 * CredentialService::MAX_ACTIVE_PER_MERCHANT unrevoked credentials at once
 * and must revoke a stale one before minting another.
 */
final class CredentialCapReachedException extends DomainException
{
    public static function atCap(int $cap): self
    {
        return new self(sprintf(
            'This store already has %d active API credentials, the maximum. Revoke one you no longer use, then create the new one.',
            $cap,
        ));
    }
}
