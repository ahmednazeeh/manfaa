<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use Carbon\CarbonImmutable;
use DomainException;

final class InvalidPromotionWindowException extends DomainException
{
    public static function endsBeforeStarts(CarbonImmutable $startsAt, CarbonImmutable $endsAt): self
    {
        return new self(sprintf(
            'Promotion window is empty: ends_at (%s) must be after starts_at (%s).',
            $endsAt->toIso8601String(),
            $startsAt->toIso8601String(),
        ));
    }

    /**
     * Publishing freezes the promotion for its whole stated window, so the
     * window must lie entirely in the future — a draft whose start has
     * already passed must be re-drafted with a fresh window instead.
     */
    public static function startsInPast(CarbonImmutable $startsAt, CarbonImmutable $now): self
    {
        return new self(sprintf(
            'Promotion cannot be published: starts_at (%s) is already in the past (now %s). Draft a new window.',
            $startsAt->toIso8601String(),
            $now->toIso8601String(),
        ));
    }
}
