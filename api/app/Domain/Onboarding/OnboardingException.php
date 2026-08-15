<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\Merchant;
use DomainException;

/**
 * Wizard lifecycle refusals, each carrying a machine-readable code (and,
 * for submit, the list of missing requirements) so the panel can render
 * the exact state instead of parsing prose.
 */
final class OnboardingException extends DomainException
{
    /**
     * @param  list<string>  $missing
     */
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly array $missing = [],
    ) {
        parent::__construct($message);
    }

    /** The wizard only writes while the store is draft or rejected. */
    public static function notEditable(string $status): self
    {
        return new self(
            sprintf('The store setup is not editable while the account is %s.', Merchant::statusLabel($status)),
            'setup_not_editable',
            409,
        );
    }

    /**
     * @param  list<string>  $missing
     */
    public static function incomplete(array $missing): self
    {
        return new self(
            'The store setup is not complete: '.implode(', ', $missing).'.',
            'setup_incomplete',
            422,
            $missing,
        );
    }

    /**
     * The `logos` disk refused the write. It is configured throw=false (and
     * report=false), so storeAs answers FALSE instead of raising — and
     * swallowing that would point logo_path at a file that does not exist,
     * leaving the store with a 404 where its branding should be. 503: the
     * upload is worth retrying, the request was not wrong.
     */
    public static function logoWriteFailed(): self
    {
        return new self(
            'The logo could not be stored. Please try again.',
            'logo_write_failed',
            503,
        );
    }

    /** Approve/reject act on pending_review submissions only. */
    public static function notPendingReview(string $status): self
    {
        return new self(
            sprintf('The store is %s — only a submission awaiting review can be approved or rejected.', Merchant::statusLabel($status)),
            'not_pending_review',
            409,
        );
    }
}
