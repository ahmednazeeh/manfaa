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
    public static function forSettlement(Settlement $settlement, ?string $bankRef): self
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

    /** The merchant already put this reference on a settlement receipt (pending or matched). */
    public static function forWalletTopUpHeldBySettlement(Merchant $merchant, string $bankRef): self
    {
        return new self(sprintf(
            'Bank reference %s is already recorded against a settlement payment for merchant #%d.',
            $bankRef,
            $merchant->id,
        ));
    }

    /** The credit behind this reference was already spent — on an order, a settlement payment, or a wallet. */
    public static function forWalletTopUpSpent(Merchant $merchant, string $bankRef): self
    {
        return new self(sprintf(
            'Bank reference %s is already spent on another payment; it cannot also top up the wallet of merchant #%d.',
            $bankRef,
            $merchant->id,
        ));
    }

    /** The merchant already CLAIMED this reference as a wallet top-up (pending or matched). */
    public static function forSettlementHeldByTopUp(Settlement $settlement, string $bankRef): self
    {
        return new self(sprintf(
            'Bank reference %s is already submitted as a wallet top-up; it cannot also pay settlement %s.',
            $bankRef,
            $settlement->reference,
        ));
    }

    /** The merchant already CLAIMED this transfer as a top-up (pending or matched). */
    public static function forWalletTopUpClaim(Merchant $merchant, ?string $bankRef): self
    {
        return new self(sprintf(
            'Bank reference %s is already submitted as a wallet top-up for merchant #%d.',
            $bankRef ?? '(none)',
            $merchant->id,
        ));
    }
}
