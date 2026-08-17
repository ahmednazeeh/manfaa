<?php

declare(strict_types=1);

namespace App\Domain\MerchantSettings;

use App\Domain\Mobile\MobileTokenService;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Management of merchant panel accounts, mirroring the admin-side
 * AdminAccountService. There is no DELETE — deactivation is the only
 * removal, so audit trails (created_by on rates, transaction event actors)
 * always keep resolving. Guards: nobody demotes or deactivates themselves,
 * and the merchant's last active OWNER can be neither deactivated nor
 * demoted.
 *
 * An account points at one of its merchant's ROLES (PLAN §13b staff
 * permissions), and who may reach this service is now a matter of the
 * `staff.view` / `staff.invite` / `staff.edit` permissions rather than a
 * tier. Which role may be handed out is RoleService's question — assigning
 * someone a role is as much a grant as writing the permissions into it.
 *
 * The last-owner guard keys on the role's immutable `is_owner` FLAG, not on
 * any permission (D4): a store whose only owner stepped down could no
 * longer touch its bank account or mint accounts, and keying the guard on
 * `staff.edit` would let it happen the moment a custom role held that
 * permission. Roles that merely carry wide permissions deliberately do NOT
 * count towards it, exactly as managers did not.
 */
final class StaffService
{
    /**
     * Advisory-lock classid serialising the last-owner guard; the objid is
     * the merchant id, so different merchants never contend. Public so
     * tests can contend on the same lock.
     */
    public const int OWNER_GUARD_LOCK_CLASS = 0x4D4F57; // 'MOW'

    public function __construct(
        private readonly RoleService $roles,
        private readonly MobileTokenService $mobileTokens,
    ) {}

    /**
     * Creates a panel account with a generated temporary password, returned
     * exactly once alongside the model — it is never retrievable again.
     *
     * The role is explicit: with a per-store role table there is no tier to
     * fall back to, and an invite that quietly picked one would be handing
     * out authority nobody asked for.
     *
     * @return array{0: MerchantUser, 1: string}
     */
    public function create(Merchant $merchant, MerchantUser $actor, string $name, string $email, MerchantRole $role): array
    {
        $this->roles->assertMayAssign($actor, $role);

        $tempPassword = Str::password(20);

        $user = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'email' => $email,
            'password' => $tempPassword,
            'merchant_role_id' => $role->getKey(),
            'is_active' => true,
        ]);

        return [$user, $tempPassword];
    }

    /**
     * The actor may be a MERCHANT USER (the panel's own staff screen) or an
     * ADMIN USER (the superadmin merchant controls). The guards degrade
     * correctly for an admin: `$target->is($actor)` is false across models,
     * so the self-demotion/self-deactivation rules simply never bind — an
     * admin is not any store's staff — while the last-active-owner guard
     * applies to both, because a store with zero active owners is locked out
     * of its settings whoever pulled the switch.
     */
    public function update(MerchantUser $target, MerchantUser|AdminUser $actor, ?MerchantRole $role = null, ?bool $isActive = null): MerchantUser
    {
        if ($role !== null) {
            // Role assignment is the merchant's own delegation question
            // (which of MY roles may I hand out) — it has no admin answer,
            // so the admin surface only ever toggles is_active. Fail loud if
            // that ever changes rather than inventing delegation semantics.
            if (! $actor instanceof MerchantUser) {
                throw new \InvalidArgumentException('Only a merchant user may assign merchant roles.');
            }

            $this->roles->assertMayAssign($actor, $role);
        }

        return DB::transaction(function () use ($target, $actor, $role, $isActive): MerchantUser {
            MerchantUser::query()->whereKey($target->getKey())->lockForUpdate()->first();
            $target->refresh();

            // Any move OFF the owner role is a demotion, however wide the
            // new role is: only the flag carries the standing the guard
            // protects, and a role that merely holds `staff.edit` today
            // can be edited to hold nothing tomorrow.
            $wasOwner = $target->isOwner();
            $demoting = $role !== null && ! $role->is_owner && $wasOwner;
            $deactivating = $isActive === false && $target->is_active;

            // The last-owner guard runs first: losing every active owner
            // locks the merchant's whole settings surface permanently.
            if (($demoting || $deactivating) && $wasOwner && $target->is_active) {
                // Serialise concurrent guard evaluations per merchant. Two
                // simultaneous demotes/deactivations of DIFFERENT owners
                // lock different target rows, and each plain READ COMMITTED
                // exists() would still see the other's uncommitted owner
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
                $target->merchant_role_id = $role->getKey();
            }

            if ($isActive !== null) {
                $target->is_active = $isActive;
            }

            $target->save();

            // Deactivation must DESTROY the app credentials, not merely make
            // them refuse. EnsureMobileToken already turns a deactivated
            // account away, but the rows survive — so reactivating someone
            // months later would bring a live token on a phone they no
            // longer hold straight back to life, with nobody having
            // re-entered a password. This is the same act as the removal
            // itself: "no DELETE, deactivation is the only removal" is only
            // true if deactivation actually removes the way back in.
            if ($deactivating) {
                $this->mobileTokens->revokeEverything($target);
            }

            return $target;
        });
    }

    private function anotherActiveOwnerExists(MerchantUser $target): bool
    {
        return MerchantUser::query()
            ->whereKeyNot($target->getKey())
            ->where('merchant_id', $target->merchant_id)
            ->where('is_active', true)
            ->whereHas('role', function (Builder $query) {
                // Correlated back to the user's own merchant for the same
                // reason MerchantUser::can() is: role ids are shared
                // sequential integers, and another store's owner role must
                // never read as this store's last owner.
                $query->where('is_owner', true)
                    ->whereColumn('merchant_roles.merchant_id', 'merchant_users.merchant_id');
            })
            ->exists();
    }
}
