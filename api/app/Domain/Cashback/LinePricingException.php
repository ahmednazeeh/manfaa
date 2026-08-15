<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use RuntimeException;

/**
 * A lined credit failed one of the published line rules. $errorCode is the
 * stable machine code both endpoints answer with (422): unknown_category,
 * inactive_category, duplicate_category_line, lines_sum_mismatch.
 */
final class LinePricingException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownCategory(string $slug): self
    {
        // A slug belonging to ANOTHER merchant answers identically — the
        // lookup is scoped to the authenticated merchant by design.
        return new self('unknown_category', sprintf('No product category "%s" exists for this merchant.', $slug));
    }

    public static function inactiveCategory(string $slug): self
    {
        return new self('inactive_category', sprintf('Product category "%s" is deactivated and cannot price new credits.', $slug));
    }

    public static function duplicateCategoryLine(?string $slug): self
    {
        return new self('duplicate_category_line', $slug === null
            ? 'The default (null-category) line appears more than once.'
            : sprintf('Product category "%s" appears on more than one line.', $slug));
    }

    public static function linesSumMismatch(int $sumLaari, int $eligibleLaari): self
    {
        return new self('lines_sum_mismatch', sprintf(
            'Line amounts sum to %d laari but eligible_amount is %d — they must be equal.',
            $sumLaari,
            $eligibleLaari,
        ));
    }
}
