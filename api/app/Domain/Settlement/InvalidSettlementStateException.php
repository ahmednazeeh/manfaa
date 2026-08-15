<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Settlement;
use App\Models\SettlementPayment;
use DomainException;

/**
 * Every message here is echoed verbatim by the merchant and admin panels
 * (`abort(409, $e->getMessage())`), so the state is named with
 * SettlementState::label() rather than `->value` — PLAN §13b task #22, no
 * raw snake_case in rendered output. The same applies to the `$expected`
 * phrase callers pass: it is prose ("a submitted batch"), never a state key.
 */
final class InvalidSettlementStateException extends DomainException
{
    public static function forAction(Settlement $settlement, string $action, string $expected): self
    {
        return new self(sprintf(
            'Settlement %s is %s — %s requires %s.',
            $settlement->reference,
            $settlement->state->label(),
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

    /**
     * Receipt-first reject (§1): a batch whose payment was already matched
     * has confirmed customers' cashback through the state machine. Rejecting
     * it would have to un-confirm them — corrections after confirmation are
     * adjustments (§13), never a release.
     */
    public static function rejectAfterMatching(Settlement $settlement): self
    {
        return new self(sprintf(
            'Settlement %s has already had a payment matched — rejecting the receipt is no longer possible; correct it with an adjustment.',
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
