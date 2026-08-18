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
            'Merchant %s is %s — credits require an active merchant.',
            $merchant->name,
            Merchant::statusLabel($merchant->status),
        ));
    }

    /**
     * A store that PAUSED ITSELF. Deliberately a different sentence from the
     * status one: nothing is wrong with this account, its own owner turned
     * cashback off, and the person reading this at the till needs to know
     * that it takes one tap to turn back on — not to think the store is in
     * trouble with the platform.
     */
    public static function paused(Merchant $merchant): self
    {
        return new self(sprintf(
            '%s is paused on the app, so it is not giving cashback right now — resume the store to start again.',
            $merchant->name,
        ));
    }
}
