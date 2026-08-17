<?php

declare(strict_types=1);

namespace App\Domain\Mobile;

use App\Models\Customer;
use App\Models\MerchantUser;

/**
 * The two mobile apps, and therefore the only two token audiences that exist.
 *
 * This enum is the single place that answers, for each app: which model may
 * hold its tokens, which session guard its user is set on so the existing
 * controllers keep working untouched, and which ability its tokens carry.
 *
 * It is deliberately a CLOSED set. Sanctum will happily authenticate any
 * tokenable that uses HasApiTokens — the trait is already on Customer,
 * MerchantUser, AdminUser and Merchant — so "which audience is this?" can
 * never be inferred from the token. It has to be asserted against a fixed
 * list, and this is the list. Admin is absent on purpose: the admin panel
 * stays web-only and session-only, and there is no build in which an admin
 * token should exist.
 */
enum MobileAudience: string
{
    case Customer = 'customer';
    case Merchant = 'merchant';

    /**
     * The guard whose user the middleware sets, so that every existing
     * `$request->user('customer')` and `$request->user('merchant')` — and
     * therefore EnsureMerchantPermission, EnsureMerchantApproved and every
     * controller behind them — resolves exactly as it does on the web.
     */
    public function guard(): string
    {
        return match ($this) {
            self::Customer => 'customer',
            self::Merchant => 'merchant',
        };
    }

    /**
     * The ONLY class a token of this audience may belong to.
     *
     * @return class-string<Customer|MerchantUser>
     */
    public function model(): string
    {
        return match ($this) {
            self::Customer => Customer::class,
            self::Merchant => MerchantUser::class,
        };
    }

    /**
     * The single ability every token of this audience carries.
     *
     * Belt to the instanceof brace: a token minted for the customer app
     * fails the merchant app's check twice over — wrong class AND missing
     * ability. Never the '*' wildcard, which would make the ability half of
     * the check vacuous.
     */
    public function ability(): string
    {
        return 'mobile:'.$this->value;
    }

    /**
     * How long a freshly minted token lives.
     *
     * The customer's phone is personal and re-authenticating is friction on
     * the exact screen that has to be instant at a till queue, so a year.
     * A merchant token sits on a device that is shared by a shift, left on a
     * counter, and often not owned by the person using it — a quarter, after
     * which someone has to prove they still work there. Revocation is the
     * real control in both cases; expiry is what covers the device nobody
     * remembers to report.
     */
    public function tokenLifetimeDays(): int
    {
        return match ($this) {
            self::Customer => 365,
            self::Merchant => 90,
        };
    }
}
