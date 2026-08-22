<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use DomainException;

final class FulfilmentException extends DomainException
{
    /**
     * The order has not been paid for.
     *
     * Its own refusal rather than a state-machine error, because the shop
     * has done nothing wrong and the sentence has to say so: they are
     * waiting on the customer's money, not on their own action.
     */
    public static function notPaid(): self
    {
        return new self(
            'This order has not been paid for yet. It cannot be worked on '
            .'until the transfer is verified.'
        );
    }

    public static function cannotMove(string $from, string $to): self
    {
        return new self(sprintf('An order that is %s cannot become %s.', $from, $to));
    }
}
