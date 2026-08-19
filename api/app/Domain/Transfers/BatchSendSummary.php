<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

/** What one pass of a batch through the bank API did. */
final readonly class BatchSendSummary
{
    public function __construct(
        public int $sent = 0,
        /** Accepted and waiting on a second approver. Alive, not failed. */
        public int $parked = 0,
        /** Refused in a way that proves no debit — safe to send again. */
        public int $failed = 0,
        /** Refused ambiguously. Left alone for a person; never re-sent blind. */
        public int $needsReview = 0,
        public int $skipped = 0,
    ) {}

    public function with(TransferOutcome $outcome): self
    {
        return new self(
            sent: $this->sent + ($outcome === TransferOutcome::Sent ? 1 : 0),
            parked: $this->parked + ($outcome === TransferOutcome::PendingApproval ? 1 : 0),
            failed: $this->failed + ($outcome === TransferOutcome::FailedRetryable ? 1 : 0),
            needsReview: $this->needsReview + ($outcome === TransferOutcome::FailedNeedsReview ? 1 : 0),
            skipped: $this->skipped,
        );
    }

    public function skip(): self
    {
        return new self($this->sent, $this->parked, $this->failed, $this->needsReview, $this->skipped + 1);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'sent' => $this->sent,
            'parked' => $this->parked,
            'failed' => $this->failed,
            'needs_review' => $this->needsReview,
            'skipped' => $this->skipped,
        ];
    }
}
