<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Settlement;
use App\Models\SettlementPayment;
use DomainException;

final class InvalidSettlementStateException extends DomainException
{
    public static function forAction(Settlement $settlement, string $action, string $expected): self
    {
        return new self(sprintf(
            'Settlement %s is %s — %s requires %s.',
            $settlement->reference,
            $settlement->state->value,
            $action,
            $expected,
        ));
    }

    public static function cancelWithMoneyReceived(Settlement $settlement): self
    {
        return new self(sprintf(
            'Settlement %s has received money and can no longer be cancelled.',
            $settlement->reference,
        ));
    }

    public static function paymentNotPending(SettlementPayment $payment): self
    {
        return new self(sprintf(
            'Settlement payment #%d is %s — only a pending payment can be matched.',
            $payment->getKey(),
            $payment->state,
        ));
    }
}
