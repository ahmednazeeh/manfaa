<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * Everything about a report render that is not the window or the merchant
 * (owner, 2026-08-24): whether reversed transaction rows are in it, and who
 * is about to read it.
 *
 * Both defaults are the SAFE direction, and that is the point of the class.
 * A caller that constructs a report and passes nothing gets reversals
 * excluded and names masked — so a new caller added a year from now, by
 * somebody who never read this file, cannot accidentally publish a report
 * that overstates what merchants owe or a JSON body full of real bank
 * account numbers. Both mistakes have to be made on purpose, out loud:
 * `ReportOptions::of(includeReversed: true)` and `->forExport()`.
 */
final readonly class ReportOptions
{
    private function __construct(
        /**
         * Whether transaction rows in state `reversed` appear on the
         * TRANSACTION sheets. It governs rows, never ledger journals — see
         * EarningsReport, which is unaffected by this flag on purpose.
         */
        public bool $includeReversed,
        public Masking $masking,
    ) {}

    public static function of(bool $includeReversed = false): self
    {
        return new self($includeReversed, Masking::Masked);
    }

    /** Reversals out, names masked — what a caller that says nothing gets. */
    public static function default(): self
    {
        return self::of();
    }

    /**
     * The same window and the same rows, rendered whole. Private to the
     * domain by convention: Report::forExport() is the call site, and
     * BaseReport::previewPayload() is the guard that makes the pairing
     * safe.
     */
    public function unmasked(): self
    {
        return new self($this->includeReversed, Masking::Full);
    }

    public function isMasked(): bool
    {
        return $this->masking === Masking::Masked;
    }

    /**
     * @return array{include_reversed: bool, masking: string}
     */
    public function toArray(): array
    {
        return [
            'include_reversed' => $this->includeReversed,
            'masking' => $this->masking === Masking::Masked ? 'masked' : 'full',
        ];
    }
}
