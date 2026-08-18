<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use DomainException;

/**
 * Asked to act on a marketplace enrolment that does not exist.
 *
 * Distinct from "your papers are incomplete" on purpose: a store that never
 * opted in and a store halfway through applying need different sentences,
 * and answering both with a list of missing documents tells the first one to
 * upload papers for an application they never started.
 */
final class NotEnrolledException extends DomainException
{
    public static function forSubmit(): self
    {
        return new self('This store has not opted in to the marketplace yet.');
    }

    public static function forReview(): self
    {
        return new self('This store has no marketplace application to review.');
    }
}
