<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Money\Laari;
use DomainException;

/**
 * The claimed top-up is under the platform's minimum
 * (PlatformConfig `wallet_top_up_min_laari`). Enforced in the domain so
 * every entry point — web, mobile, whatever comes next — answers the same.
 */
final class WalletTopUpBelowMinimumException extends DomainException
{
    public static function of(Laari $amount, int $minimumLaari): self
    {
        return new self(sprintf(
            'A wallet top-up must be at least MVR %s; MVR %s was claimed.',
            Laari::of($minimumLaari)->formatMvr(),
            $amount->formatMvr(),
        ));
    }
}
