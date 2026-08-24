<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Merchant;
use DomainException;

/**
 * The merchant already has WalletTopUps::MAX_PENDING claims waiting on the
 * bank or an admin. Each claim is a slip, an OCR run and a bank-polling
 * loop; a store with three transfers unaccounted for has a reconciliation
 * problem, not a form problem, and the queue is where that gets solved.
 */
final class TooManyPendingTopUpsException extends DomainException
{
    public static function forMerchant(Merchant $merchant, int $pending): self
    {
        return new self(sprintf(
            'Merchant #%d already has %d top-ups waiting to be matched. Wait for those before submitting another.',
            $merchant->id,
            $pending,
        ));
    }
}
