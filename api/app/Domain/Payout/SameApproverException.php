<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\AdminUser;
use App\Models\PayoutBatch;
use DomainException;

/**
 * Dual approval means two distinct admins (§6) — the same admin approving
 * twice is refused in the domain, not the UI.
 */
final class SameApproverException extends DomainException
{
    public static function for(PayoutBatch $batch, AdminUser $approver): self
    {
        return new self(sprintf(
            'Payout batch %s was already approved by admin #%d — the second approval must come from a different admin.',
            $batch->reference,
            $approver->id,
        ));
    }
}
