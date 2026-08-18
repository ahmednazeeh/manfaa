<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use DomainException;

final class WalletException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function insufficient(int $balanceLaari): self
    {
        return new self(
            sprintf('Your wallet holds MVR %s.', number_format($balanceLaari / 100, 2)),
            'insufficient_balance',
        );
    }

    public static function invalidAmount(): self
    {
        return new self('Enter an amount to withdraw.', 'invalid_amount');
    }

    public static function noBankAccount(): self
    {
        return new self('Add your bank account before withdrawing.', 'no_bank_account');
    }
}
