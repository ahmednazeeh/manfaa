<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use DomainException;

/**
 * An uploaded transfer sheet that cannot be applied. Thrown inside the import
 * transaction, so a rejected sheet changes nothing at all.
 */
final class ImportRowException extends DomainException
{
    public static function unreadable(): self
    {
        return new self('The uploaded file could not be read as a transfer sheet — upload the exported .xlsx, or that same sheet saved as CSV.');
    }

    public static function missingHeadings(): self
    {
        return new self(sprintf(
            'The uploaded sheet has no "%s" and "%s" columns — upload the exported transfer sheet with the reference column filled in.',
            TransferSheetExporter::KEY_HEADING,
            TransferSheetExporter::REFERENCE_HEADING,
        ));
    }

    public static function foreignKey(string $key, PayoutBatch $batch): self
    {
        return new self(sprintf(
            'Idempotency key %s is not part of payout batch %s — the sheet belongs to another run, and nothing in it was applied.',
            $key,
            $batch->reference,
        ));
    }

    public static function alreadyResolved(PayoutItem $item): self
    {
        return new self(sprintf(
            'Payout item %s is already %s and cannot take another transfer reference.',
            $item->idempotency_key,
            $item->state->value,
        ));
    }
}
