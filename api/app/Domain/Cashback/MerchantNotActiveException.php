<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\Merchant;
use DomainException;

/**
 * Manual credits are refused outright for non-active merchants. The lenient
 * record-as-ineligible behaviour (§7) belongs to the POS ingestion path only.
 */
final class MerchantNotActiveException extends DomainException
{
    public static function for(Merchant $merchant): self
    {
        return new self(sprintf(
            'Merchant %s is %s — manual credits require an active merchant.',
            $merchant->name,
            $merchant->status,
        ));
    }
}
