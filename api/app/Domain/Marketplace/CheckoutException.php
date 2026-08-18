<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use DomainException;

/**
 * The cart cannot become an order yet.
 *
 * Every case names WHICH shop is at fault where it can. "Checkout failed" is
 * not a sentence a shopper can act on; "Horizon Bookstore needs MVR 30 more"
 * is.
 */
final class CheckoutException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self('Your cart is empty.', 'cart_empty');
    }

    public static function belowMinimum(string $store, int $shortfallLaari): self
    {
        return new self(
            sprintf('%s needs MVR %s more before it can deliver.', $store, number_format($shortfallLaari / 100, 2)),
            'below_minimum',
        );
    }

    public static function unavailable(string $store): self
    {
        return new self(
            sprintf('Something from %s is no longer available. Please review your cart.', $store),
            'item_unavailable',
        );
    }

    public static function needsAddress(): self
    {
        return new self('Choose a delivery address first.', 'address_required');
    }
}
