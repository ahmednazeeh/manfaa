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
 * and the merchant's last active OWNER can be neither deactivated nor
 * demoted.
 *
 * Three tiers since 2026-08-15 (PLAN §1): owner, manager, staff. An owner
 * assigns any of them — at invite or later. A MANAGER never reaches this
 * service: the staff routes are merchant.role:owner. Managers deliberately
 * do NOT count towards the last-owner guard — a store whose only owner
 * stepped down to manager could no longer touch its bank account or mint
 * accounts, which is precisely the lockout the guard exists to prevent.
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
     * Creates a panel account with a generated temporary password, returned
     * exactly once alongside the model — it is never retrievable again.
     *
     * The tier defaults to `staff` (the back-compatible invite); an owner
     * may invite a manager, or a second owner, straight away.
     *
     * @return array{0: MerchantUser, 1: string}
     */
    public function create(Merchant $merchant, string $name, string $email, string $role = 'staff'): array
    {
        $tempPassword = Str::password(20);

        $user = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'email' => $email,
            'password' => $tempPassword,
            'role' => $role,
            'is_active' => true,
        ]);

        return [$user, $tempPassword];
    }

    public function update(MerchantUser $target, MerchantUser $actor, ?string $role = null, ?bool $isActive = null): MerchantUser
    {
        return DB::transaction(function () use ($target, $actor, $role, $isActive): MerchantUser {
            MerchantUser::query()->whereKey($target->getKey())->lockForUpdate()->first();
            $target->refresh();

            // Any move OFF owner is a demotion — to manager just as much as
            // to staff: both drop the bank account, staff management and
            // credential surfaces, and both can strand the merchant if this
            // was the last owner.
            $demoting = $role !== null && $role !== 'owner' && $target->role === 'owner';
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
