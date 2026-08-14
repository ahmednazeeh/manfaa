<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;
use DomainException;

final class InvalidPayoutBatchStateException extends DomainException
{
    public static function approve(PayoutBatch $batch): self
    {
        return self::action($batch, 'approved', PayoutBatchState::Draft);
    }

    public static function export(PayoutBatch $batch): self
    {
        return new self(sprintf(
            'Payout batch %s is %s and cannot be exported — only an approved batch, or a processing batch whose results have not started arriving, can.',
            $batch->reference,
            $batch->state->value,
        ));
    }

    public static function import(PayoutBatch $batch): self
    {
        return new self(sprintf(
            'Payout batch %s is %s and cannot take a result import — the bank file must be exported first.',
            $batch->reference,
            $batch->state->value,
        ));
    }

    public static function cancel(PayoutBatch $batch): self
    {
        return self::action($batch, 'cancelled', PayoutBatchState::Draft);
    }

    private static function action(PayoutBatch $batch, string $verb, PayoutBatchState $required): self
    {
        return new self(sprintf(
            'Payout batch %s is %s and cannot be %s — only a %s batch can.',
            $batch->reference,
            $batch->state->value,
            $verb,
            $required->value,
        ));
    }
}
