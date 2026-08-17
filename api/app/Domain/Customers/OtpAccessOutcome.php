<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Customer;

/**
 * What one proof of phone possession earned: an existing account to sign in
 * (the caller mints the bearer token), or a signup token to finish
 * registration with. Exactly one of the two is set.
 */
final readonly class OtpAccessOutcome
{
    private function __construct(
        public ?Customer $customer,
        public ?string $signupToken,
    ) {}

    public static function signIn(Customer $customer): self
    {
        return new self($customer, null);
    }

    public static function signUp(string $signupToken): self
    {
        return new self(null, $signupToken);
    }
}
