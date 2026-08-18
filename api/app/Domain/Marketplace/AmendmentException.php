<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use DomainException;

/** Why a reduction was refused, in words the shop can act on. */
final class AmendmentException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function notAmendable(string $state): self
    {
        return new self(
            sprintf('An order that is %s can no longer be changed.', $state),
            'not_amendable',
        );
    }

    public static function cannotIncrease(): self
    {
        return new self(
            'An order can only be reduced. To supply more, ask the customer to place a new order.',
            'cannot_increase',
        );
    }

    public static function wouldEmptyOrder(): self
    {
        return new self(
            'That would leave nothing to fulfil — reject the order instead, so the customer is refunded in full.',
            'would_empty_order',
        );
    }

    public static function nothingChanged(): self
    {
        return new self('Nothing was changed.', 'nothing_changed');
    }

    public static function unknownItem(): self
    {
        return new self('That item is not on this order.', 'unknown_item');
    }
}
