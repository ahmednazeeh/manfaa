<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Merchant;
use App\Models\Settlement;
use DomainException;

/**
 * The same bank reference was already recorded against this settlement (or
 * merchant wallet) — the transfer is in the system and recording it again
 * would book the same cash twice. Raised off the unique database indexes,
 * which are the authority on duplicates.
 */
final class DuplicateBankRefException extends DomainException
{
    public static function forSettlement(Settlement $settlement, string $bankRef): self
    {
        return new self(sprintf(
            'Bank reference %s is already recorded against settlement %s.',
            $bankRef,
            $settlement->reference,
        ));
    }

    public static function forWalletTopUp(Merchant $merchant, string $bankRef): self
    {
        return new self(sprintf(
            'Bank reference %s is already recorded as a wallet top-up for merchant #%d.',
            $bankRef,
            $merchant->id,
        ));
    }
}
