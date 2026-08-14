<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Superadmin management of admin accounts. There is no DELETE — deactivation
 * is the only removal, so audit trails (created_by, approved_by, matched_by)
 * always keep resolving. Guards: nobody demotes or deactivates themselves,
 * and the last active superadmin can be neither deactivated nor demoted.
 */
final class AdminAccountService
{
    /**
     * Advisory-lock keys (classid, objid) serialising the last-superadmin
     * guard platform-wide. Public so tests can contend on the same lock.
     */
    public const int SUPERADMIN_GUARD_LOCK_CLASS = 0x41444D; // 'ADM'

    public const int SUPERADMIN_GUARD_LOCK_KEY = 1;

    /**
     * Creates the account with a generated temporary password, returned
     * exactly once alongside the model — it is never retrievable again.
     *
     * @return array{0: AdminUser, 1: string}
     */
    public function create(string $name, string $email, string $role): array
    {
        $tempPassword = Str::password(20);

        $admin = AdminUser::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $tempPassword,
            'role' => $role,
            'is_active' => true,
        ]);

        return [$admin, $tempPassword];
    }

    public function update(AdminUser $target, AdminUser $actor, ?string $role = null, ?bool $isActive = null): AdminUser
    {
        return DB::transaction(function () use ($target, $actor, $role, $isActive): AdminUser {
            AdminUser::query()->whereKey($target->getKey())->lockForUpdate()->first();
            $target->refresh();

            $demoting = $role === 'admin' && $target->role === 'superadmin';
            $deactivating = $isActive === false && $target->is_active;

            // The last-superadmin guard runs first: losing every active
            // superadmin locks this whole surface permanently.
            if (($demoting || $deactivating) && $target->role === 'superadmin' && $target->is_active) {
                // Serialise concurrent guard evaluations platform-wide. Two
                // simultaneous demotes/deactivations of DIFFERENT superadmins
                // lock different target rows, and each plain READ COMMITTED
                // exists() would still see the other's uncommitted
                // 'superadmin' row — both would pass and zero active
                // superadmins could remain. The constant-key
                // transaction-scoped advisory lock makes the second
                // evaluation wait for the first commit, which its fresh
                // statement snapshot then sees.
                DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [
                    self::SUPERADMIN_GUARD_LOCK_CLASS,
                    self::SUPERADMIN_GUARD_LOCK_KEY,
                ]);

                if (! $this->anotherActiveSuperadminExists($target)) {
                    throw AdminAccountException::lastActiveSuperadmin();
                }
            }

            if ($demoting && $target->is($actor)) {
                throw AdminAccountException::cannotDemoteSelf();
            }

            if ($deactivating && $target->is($actor)) {
                throw AdminAccountException::cannotDeactivateSelf();
            }

            if ($role !== null) {
                $target->role = $role;
            }

            if ($isActive !== null) {
                $target->is_active = $isActive;
            }

            $target->save();

            return $target;
        });
    }

    private function anotherActiveSuperadminExists(AdminUser $target): bool
    {
        return AdminUser::query()
            ->whereKeyNot($target->getKey())
            ->where('role', 'superadmin')
            ->where('is_active', true)
            ->exists();
    }
}
