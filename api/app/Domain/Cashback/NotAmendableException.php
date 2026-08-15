<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use DomainException;

/**
 * A sale the merchant may no longer correct themselves. Carries a machine
 * code so the panel can say WHY rather than "not allowed": the two reasons
 * lead to different next steps — wait, or ask the platform.
 */
final class NotAmendableException extends DomainException
{
    private function __construct(
        // NOT $code: Exception already declares a protected int $code, and
        // shadowing it with a readonly string is a fatal at class-load
        // time — which PHP reports by killing the process with no output
        // at all. Matches LinePricingException's naming.
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function state(TransactionState $state): self
    {
        return new self(
            'not_amendable_state',
            sprintf(
                'This sale is %s and can no longer be edited here — the validation window has closed. Contact the platform for an adjustment.',
                $state->label(),
            ),
        );
    }

    public static function backdated(): self
    {
        return new self(
            'backdated_irreversible',
            'This sale was credited outside the validation window, so it is final. Contact the platform for an adjustment.',
        );
    }
}
