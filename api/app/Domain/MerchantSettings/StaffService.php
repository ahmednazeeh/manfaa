<?php

declare(strict_types=1);

namespace App\Domain\MerchantSettings;

use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner management of merchant panel accounts, mirroring the admin-side
 * AdminAccountService. There is no DELETE — deactivation is the only
 * removal, so audit trails (created_by on rates, transaction event actors)
 * always keep resolving. Guards: nobody demotes or deactivates themselves,
 * and the merchant's last active owner can be neither deactivated nor
 * demoted.
 */
final class StaffService
{
    /**
     * Advisory-lock classid serialising the last-owner guard; the objid is
     * the merchant id, so different merchants never contend. Public so
     * tests can contend on the same lock.
     */
    public const int OWNER_GUARD_LOCK_CLASS = 0x4D4F57; // 'MOW'

    /**
     * Creates a staff account with a generated temporary password, returned
     * exactly once alongside the model — it is never retrievable again.
     *
     * @return array{0: MerchantUser, 1: string}
     */
    public function create(Merchant $merchant, string $name, string $email): array
    {
        $tempPassword = Str::password(20);

        $user = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'email' => $email,
            'password' => $tempPassword,
            'role' => 'staff',
            'is_active' => true,
        ]);

        return [$user, $tempPassword];
    }

    public function update(MerchantUser $target, MerchantUser $actor, ?string $role = null, ?bool $isActive = null): MerchantUser
    {
        return DB::transaction(function () use ($target, $actor, $role, $isActive): MerchantUser {
            MerchantUser::query()->whereKey($target->getKey())->lockForUpdate()->first();
            $target->refresh();

            $demoting = $role === 'staff' && $target->role === 'owner';
            $deactivating = $isActive === false && $target->is_active;

            // The last-owner guard runs first: losing every active owner
            // locks the merchant's whole settings surface permanently.
            if (($demoting || $deactivating) && $target->role === 'owner' && $target->is_active) {
                // Serialise concurrent guard evaluations per merchant. Two
                // simultaneous demotes/deactivations of DIFFERENT owners
                // lock different target rows, and each plain READ COMMITTED
                // exists() would still see the other's uncommitted 'owner'
                // row — both would pass and zero active owners could
                // remain. The merchant-keyed transaction-scoped advisory
                // lock makes the second evaluation wait for the first
                // commit, which its fresh statement snapshot then sees.
                DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [
                    self::OWNER_GUARD_LOCK_CLASS,
                    (int) $target->merchant_id,
                ]);

                if (! $this->anotherActiveOwnerExists($target)) {
                    throw StaffException::lastActiveOwner();
                }
            }

            if ($demoting && $target->is($actor)) {
                throw StaffException::cannotDemoteSelf();
            }

            if ($deactivating && $target->is($actor)) {
                throw StaffException::cannotDeactivateSelf();
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

    private function anotherActiveOwnerExists(MerchantUser $target): bool
    {
        return MerchantUser::query()
            ->whereKeyNot($target->getKey())
            ->where('merchant_id', $target->merchant_id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->exists();
    }
}
