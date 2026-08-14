<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Models\Merchant;
use DomainException;

final class BranchNotOwnedException extends DomainException
{
    public static function for(Merchant $merchant, int $branchId): self
    {
        return new self(sprintf(
            'Branch #%d does not belong to merchant #%d — a promotion can only be scoped to the merchant\'s own branch.',
            $branchId,
            $merchant->getKey(),
        ));
    }
}
