<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

/**
 * What the bank said, reduced to the only four answers that matter.
 *
 * The distinction the upstream forces on us, and the one worth naming
 * loudest: PARKED is not FAILED. A dual-control profile answers 200 with
 * `pending_approval`, an empty trx_id and an approval_id — that money is
 * waiting for a second human, not refused. Re-sending it would pay twice.
 */
enum TransferOutcome: string
{
    /** The bank moved the money. `trxId` is its reference. */
    case Sent = 'sent';

    /**
     * Accepted and waiting on dual control. NEVER re-send: `approvalId` is
     * an approvals-queue record id, not a transaction reference, and the
     * transfer is very much alive.
     */
    case PendingApproval = 'pending_approval';

    /**
     * Refused in a way that PROVES no debit occurred, so the same
     * `internal_ref` may be sent again.
     */
    case FailedRetryable = 'failed_retryable';

    /**
     * Refused, and we cannot prove nothing left the account. A human looks
     * before anything is sent again.
     */
    case FailedNeedsReview = 'failed_needs_review';
}

/** One transfer attempt's answer, already interpreted. */
final readonly class TransferResult
{
    public function __construct(
        public TransferOutcome $outcome,
        public ?string $trxId = null,
        public ?string $approvalId = null,
        public ?string $errorCode = null,
        public ?string $message = null,
        /** True when this answer came from the upstream recognising a repeat. */
        public bool $wasDuplicate = false,
    ) {}
}
