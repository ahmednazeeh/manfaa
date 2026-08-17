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
            'Promotion window is empty: the end (%s) must be after the start (%s).',
            self::businessLocal($endsAt),
            self::businessLocal($startsAt),
        ));
    }

    /**
     * Publishing freezes the promotion for its whole stated window. A start
     * up to 24 hours back still publishes (MR8 owner decision — a promo set
     * to "today" in Maldives time must not be refused because the server's
     * UTC clock is still on yesterday); anything older must be re-drafted
     * with a fresh window.
     */
    public static function startsTooFarInPast(CarbonImmutable $startsAt, CarbonImmutable $now): self
    {
        return new self(sprintf(
            'Promotion cannot be published: its start (%s) is more than 24 hours ago (today is %s). Draft a new window.',
            self::businessDate($startsAt),
            self::businessDate($now),
        ));
    }

    /**
     * MR8: refusal messages carry human LOCAL dates in the business
     * timezone — the owner picked "the 18th" in Maldives time and was told
     * "the 17th" by a raw UTC timestamp. Never a shifted ISO string.
     */
    private static function businessDate(CarbonImmutable $at): string
    {
        return $at->setTimezone(config('app.business_timezone'))->format('j M Y');
    }

    /**
     * Same rule where the TIME is the point (an empty window is usually two
     * instants on one local day): local business date plus wall-clock time.
     */
    private static function businessLocal(CarbonImmutable $at): string
    {
        return $at->setTimezone(config('app.business_timezone'))->format('j M Y H:i');
    }
}
