<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use DomainException;

/**
 * The uploaded payment slip is not a slip we will store: too large, empty,
 * or its CONTENT is not one of the four accepted formats. Carries a
 * machine-readable code so the panel can tell the merchant which of the two
 * it was without parsing prose.
 */
final class InvalidSlipException extends DomainException
{
    public const string CODE_TOO_LARGE = 'slip_too_large';

    public const string CODE_UNSUPPORTED = 'slip_unsupported_type';

    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function tooLarge(int $bytes, int $maxBytes): self
    {
        return new self(self::CODE_TOO_LARGE, sprintf(
            'The payment slip is %d bytes; the maximum is %d bytes (%d MB).',
            $bytes,
            $maxBytes,
            intdiv($maxBytes, 1024 * 1024),
        ));
    }

    public static function unsupported(): self
    {
        return new self(self::CODE_UNSUPPORTED, 'The payment slip must be a JPEG, PNG, WebP or PDF file.');
    }
}
