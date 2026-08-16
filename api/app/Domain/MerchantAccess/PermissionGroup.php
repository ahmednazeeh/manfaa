<?php

declare(strict_types=1);

namespace App\Domain\MerchantAccess;

/**
 * How the roles screen stacks the catalogue. The five groups are the
 * merchant panel's own navigation sections (apps/merchant menu.ts), so a
 * shopkeeper building a role reads the same headings they use to find the
 * screen — "everything under Till" is a sentence they can already say.
 */
enum PermissionGroup: string
{
    case Till = 'till';
    case Money = 'money';
    case Marketing = 'marketing';
    case Store = 'store';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Till => 'Till',
            self::Money => 'Money',
            self::Marketing => 'Marketing',
            self::Store => 'Store',
            self::Account => 'Account',
        };
    }

    /**
     * The group's permissions, in catalogue order.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission) => $permission->group() === $this,
        ));
    }
}
