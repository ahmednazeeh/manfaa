<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

/**
 * The closed set of abilities a vendor token can carry (§9.1). Every /v1
 * operation declares exactly one required ability (`x-required-ability` in
 * docs/openapi.yaml); a valid token lacking it answers 403.
 */
enum VendorAbility: string
{
    case TransactionsWrite = 'transactions:write';
    case TransactionsReverse = 'transactions:reverse';
    case RatesRead = 'rates:read';
    case CustomersLookup = 'customers:lookup';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $ability) => $ability->value, self::cases());
    }
}
