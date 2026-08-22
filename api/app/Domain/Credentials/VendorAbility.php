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
     * Register and remove the credential's OWN webhook endpoints over /v1
     * (owner, 2026-08-22). Endpoints registered this way belong to the
     * merchant, hear only that merchant's events, and are switched off with
     * the credential — so a plugin can set up its webhook with no manual step.
     */
    case WebhooksManage = 'webhooks:manage';

    /**
     * What this lets the platform do, addressed to the SHOPKEEPER who is
     * approving it — "IsleBooks would like to …".
     *
     * Written as consequences rather than endpoint names: nobody deciding
     * whether to trust an app can weigh `transactions:write`, but anybody
     * can weigh "record sales and give your customers cashback".
     */
    public function consentLine(): string
    {
        return match ($this) {
            self::TransactionsWrite => 'Record sales and give your customers cashback',
            self::TransactionsReverse => 'Reverse sales it recorded, for refunds and mistakes',
            self::RatesRead => 'See your current cashback rate',
            self::CustomersLookup => 'Look up a customer by their code or phone number',
            self::WebhooksManage => 'Register web addresses to be told when your rate changes or a sale is reversed',
        };
    }

    /**
     * The second sentence for an ability that deserves a second thought.
     * Null where the line above already says everything.
     */
    public function consentCaution(): ?string
    {
        return match ($this) {
            // Returns a real person's NAME. A shopkeeper should know that
            // while deciding, not discover it afterwards.
            self::CustomersLookup => 'This shows a customer\'s name, so grant it only to software that needs to confirm who is at the counter.',
            // Takes cashback back off a customer.
            self::TransactionsReverse => 'Reversals take cashback back from a customer, so grant this only to software that handles your refunds.',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $ability) => $ability->value, self::cases());
    }
}
