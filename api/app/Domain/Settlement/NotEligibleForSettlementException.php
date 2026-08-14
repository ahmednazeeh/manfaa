<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Merchant;
use App\Models\Settlement;
use DomainException;

final class NotEligibleForSettlementException extends DomainException
{
    /**
     * @param  list<int>  $transactionIds
     */
    public static function forTransactions(array $transactionIds): self
    {
        return new self(sprintf(
            'Transactions [%s] are not eligible for settlement — a transaction must be payable_unfunded and not already on a non-cancelled settlement.',
            implode(', ', $transactionIds),
        ));
    }

    public static function nothingToSettle(Merchant $merchant): self
    {
        return new self(sprintf(
            'Merchant %s has no transactions eligible for settlement.',
            $merchant->name,
        ));
    }

    public static function emptyDraft(Settlement $settlement): self
    {
        return new self(sprintf(
            'Settlement %s has no lines and cannot be submitted.',
            $settlement->reference,
        ));
    }
}
