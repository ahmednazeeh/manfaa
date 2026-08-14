<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutItem;
use DomainException;

/**
 * A result file row that cannot be applied. Thrown inside the import
 * transaction, so a bad file changes nothing at all.
 */
final class ImportRowException extends DomainException
{
    public static function malformed(int $line): self
    {
        return new self(sprintf('Result file line %d is malformed — expected item_id,status[,reference[,failure_reason]].', $line));
    }

    public static function unknownItem(int $itemId): self
    {
        return new self(sprintf('Result file references payout item #%d, which is not part of this batch.', $itemId));
    }

    public static function invalidStatus(int $itemId, string $status): self
    {
        return new self(sprintf('Result file status "%s" for payout item #%d is not one of paid, failed.', $status, $itemId));
    }

    public static function alreadyResolved(PayoutItem $item): self
    {
        return new self(sprintf('Payout item #%d is already %s and cannot take another result.', $item->id, $item->state->value));
    }
}
