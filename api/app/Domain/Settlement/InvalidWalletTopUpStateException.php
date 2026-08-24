<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\WalletTopUp;
use DomainException;

/**
 * A wallet top-up claim was asked to do something its state does not allow
 * — matching or rejecting a claim that is no longer pending. Surfaces as a
 * 409: the row exists, and somebody else already decided it.
 */
final class InvalidWalletTopUpStateException extends DomainException
{
    public static function notPending(WalletTopUp $topUp, string $action): self
    {
        return new self(sprintf(
            'Wallet top-up #%d is %s; %s needs a pending claim.',
            $topUp->id,
            $topUp->state,
            $action,
        ));
    }
}
