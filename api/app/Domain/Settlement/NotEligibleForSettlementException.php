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
            'Transactions [%s] are not eligible for settlement — a transaction must be payable and unfunded, and not already on a settlement that has not been cancelled.',
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

    /**
     * Receipt-first: the selected transactions are fully covered by §7 credit
     * adjustments, so there is no transfer to evidence. A receipt against a
     * zero balance would book cash the merchant never owed; the credit-netted
     * batch settles through the wallet route instead, which draws nothing.
     */
    public static function nothingDue(Merchant $merchant): self
    {
        return new self(sprintf(
            'Merchant %s owes nothing on the selected transactions — they are fully covered by credit adjustments, so no transfer is due.',
            $merchant->name,
        ));
    }
}
