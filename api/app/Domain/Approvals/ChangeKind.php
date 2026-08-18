<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\MerchantAccess\Permission;

/**
 * The four things a live store can ask to change (MR9). The values are the
 * `kind` column's CHECK constraint, verbatim.
 */
enum ChangeKind: string
{
    case Profile = 'profile';
    case BranchCreate = 'branch_create';
    case BranchUpdate = 'branch_update';
    case BranchDelete = 'branch_delete';

    /** What the merchant sees this called, and what the notification names. */
    public function label(): string
    {
        return match ($this) {
            self::Profile => 'store profile change',
            self::BranchCreate => 'new branch',
            self::BranchUpdate => 'branch update',
            self::BranchDelete => 'branch removal',
        };
    }

    /**
     * Who hears the approve/reject outcome.
     *
     * The permission that GOVERNS the request, not one blanket slug: the
     * people who could have submitted this change are exactly the people who
     * must act on a rejection, and telling a manager their branch edit was
     * refused is useless if the message only ever reaches the owner. (The
     * owner preset holds all four, so the owner always hears regardless.)
     */
    public function permission(): Permission
    {
        return match ($this) {
            self::Profile => Permission::ProfileEdit,
            self::BranchCreate => Permission::BranchesCreate,
            self::BranchUpdate => Permission::BranchesEdit,
            self::BranchDelete => Permission::BranchesDelete,
        };
    }

    public function isBranch(): bool
    {
        return $this !== self::Profile;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }
}
