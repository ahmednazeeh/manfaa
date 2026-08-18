<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use DomainException;

/**
 * Change-review refusals, each carrying a machine-readable code so both
 * panels render the state instead of parsing prose — the shape
 * OnboardingException established for the store review queue.
 */
final class ChangeRequestException extends DomainException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    /** Approve/reject act on PENDING rows only — never twice on one row. */
    public static function notPending(string $status): self
    {
        return new self(
            sprintf('This change request is already %s.', $status),
            'change_not_pending',
            409,
        );
    }

    /**
     * The branch a queued update or removal targets is gone — deleted by an
     * admin, or by an earlier approved removal. Nothing to apply, and
     * applying "nothing" silently would report success for a change that
     * never happened.
     */
    public static function branchMissing(): self
    {
        return new self(
            'The branch this change refers to no longer exists.',
            'branch_missing',
            409,
        );
    }

    /**
     * Re-checked AT APPROVAL, not only at submit: a branch that had no
     * history when the removal was queued may have taken a sale while the
     * request sat in the queue, and that history must keep resolving.
     */
    public static function branchReferenced(): self
    {
        return new self(
            'This branch is referenced by transactions or promotions and cannot be deleted.',
            'branch_referenced',
            409,
        );
    }
}
