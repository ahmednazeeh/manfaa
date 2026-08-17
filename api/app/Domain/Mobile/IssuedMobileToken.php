<?php

declare(strict_types=1);

namespace App\Domain\Mobile;

use Carbon\CarbonImmutable;

/**
 * A freshly minted mobile token, and the only moment its plaintext exists.
 *
 * Sanctum stores a SHA-256 digest; the plaintext is returned once and is
 * unrecoverable afterwards. Nothing here is persisted — the caller hands it
 * straight to the response and lets it fall out of scope.
 */
final readonly class IssuedMobileToken
{
    public function __construct(
        public string $plainTextToken,
        public CarbonImmutable $expiresAt,
        public MobileAudience $audience,
        public string $deviceName,
    ) {}
}
