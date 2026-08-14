<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Models\Promotion;
use DomainException;

/**
 * PLAN §7: a published promotion is IMMUTABLE for its stated duration.
 * Every mutation the domain offers (publish, cancel) is draft-only; there
 * is deliberately no edit or early-end path at all, so this exception is
 * the only answer to any attempt to touch a promotion after publication.
 */
final class PromotionNotDraftException extends DomainException
{
    public static function for(Promotion $promotion, string $operation): self
    {
        return new self(sprintf(
            'Promotion #%d is %s — %s is only possible while draft. Published promotions are immutable for their stated duration.',
            $promotion->getKey(),
            $promotion->status,
            $operation,
        ));
    }
}
