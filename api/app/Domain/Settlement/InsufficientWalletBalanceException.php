<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Money\Laari;
use App\Models\Merchant;
use DomainException;

/**
 * Wallet settlement funds the whole batch or nothing — partial wallet
 * funding is deliberately unsupported (see WalletFunding).
 */
final class InsufficientWalletBalanceException extends DomainException
{
    public static function for(Merchant $merchant, int $requiredLaari, int $balanceLaari): self
    {
        return new self(sprintf(
            'Merchant %s wallet holds MVR %s but MVR %s is required — wallet settlement never funds a batch partially.',
            $merchant->name,
            Laari::of($balanceLaari)->formatMvr(),
            Laari::of($requiredLaari)->formatMvr(),
        ));
    }
}
