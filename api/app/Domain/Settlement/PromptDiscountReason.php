<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Why a batch did — or did not — get the prompt-payment discount (PLAN §1).
 * Machine keys only: the human labels live in the panels' typed label maps,
 * never in the API.
 */
enum PromptDiscountReason: string
{
    /** Granted: everything outstanding is on the batch, and every line is young. */
    case Eligible = 'eligible';

    /** The merchant still has payable transactions this batch does not cover. */
    case NotAllOutstanding = 'not_all_outstanding';

    /** At least one included line is already at or past the age window. */
    case LineTooOld = 'line_too_old';

    /**
     * At least one included line has no settlement clock at all — a payable
     * row with a null `clock_start_at` (§13b). Its age is not merely unknown
     * but unbounded: nothing ages it, because the escalation ladder, the
     * day-16 suspension and the 90-day write-off all skip it too. An
     * incentive for promptness cannot be granted against a line whose
     * promptness cannot be proved.
     */
    case ClockNotStarted = 'clock_not_started';

    /** The platform has the incentive switched off (rate 0bp). */
    case Disabled = 'disabled';
}
