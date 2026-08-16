<?php

declare(strict_types=1);

namespace App\Domain\MerchantSettings;

use App\Domain\MerchantAccess\Permission;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A merchant's own roles: the permission sets it hands to its staff (PLAN
 * §13b staff permissions). The catalogue is code; this is the one place a
 * store decides which of it a named role holds.
 *
 * The delegation rules live HERE rather than in the controller because they
 * are the security core of the round, and because two surfaces need them:
 * the roles screen writing a permission set, and the staff screen pointing
 * an account at a role. `roles.manage` without them is not a permission at
 * all — it is a second way to spell owner.
 *
 *  - You may only delegate what you hold (D5). A refusal names the slugs.
 *  - Only an owner hands out the owner role, and nothing here can MINT one:
 *    `is_owner` is seeded by RolePresetService and is not an input.
 *  - A non-owner cannot edit the role they hold themselves.
 *  - The owner role is frozen (D9): renameable, permissions un-editable,
 *    un-deletable. Manager and Staff seed as ordinary editable roles.
 */
final class RoleService
{
    /**
     * The most roles one store may hold, presets included. A cap exists
     * because the roles screen is a list a shopkeeper has to be able to
     * reason about, and because every role is a permission set someone has
     * to keep correct.
     */
    public const int MAX_PER_MERCHANT = 20;

    /**
     * @param  list<string>  $permissions
     */
    public function create(Merchant $merchant, MerchantUser $actor, string $name, ?string $nameDv, array $permissions): MerchantRole
    {
        $this->assertOwnMerchant($actor, (int) $merchant->getKey());

        $name = trim($name);
        $granted = $this->catalogued($permissions);

        // Everything in a NEW role is a grant, so the whole set is checked.
        $this->assertMayGrant($actor, $granted);

        return DB::transaction(function () use ($merchant, $name, $nameDv, $granted): MerchantRole {
            // Same discipline as the credential cap: count behind a row lock
            // on the merchant, or two simultaneous submissions both see room
            // for the twentieth role.
            Merchant::query()->whereKey($merchant->getKey())->lockForUpdate()->first();

            $existing = MerchantRole::query()->where('merchant_id', $merchant->getKey())->count();

            if ($existing >= self::MAX_PER_MERCHANT) {
                throw RoleException::capReached(self::MAX_PER_MERCHANT);
            }

            return MerchantRole::query()->create([
                'merchant_id' => $merchant->getKey(),
                'name' => $name,
                'name_dv' => $nameDv,
                'slug' => $this->uniqueSlug((int) $merchant->getKey(), $name),
                'permissions' => $this->slugs($granted),
                // Never an input: the unbounded role is seeded, once, per
                // store. A store that could mint a second one could route
                // around every rule in this class.
                'is_owner' => false,
                'is_system' => false,
            ]);
        });
    }

    /**
     * A PATCH: only the keys present are touched, which is why this takes
     * the validated array rather than a run of nullable arguments —
     * `name_dv` is itself nullable, so "clear the Dhivehi name" and "leave
     * it alone" cannot both be spelled null. Nothing outside these three
     * keys is ever read: `is_owner` and `slug` are not editable by anyone.
     *
     * @param  array{name?: string, name_dv?: string|null, permissions?: list<string>}  $changes
     */
    public function update(MerchantRole $role, MerchantUser $actor, array $changes): MerchantRole
    {
        $this->assertOwnMerchant($actor, (int) $role->merchant_id);

        $permissions = array_key_exists('permissions', $changes) ? $changes['permissions'] : null;

        // The owner role stays editable in NAME only. A store that renamed
        // it "Proprietor" keeps its own word for it; a store that could
        // strip it of `staff.edit` would make the last-owner guard
        // meaningless (§2.3).
        if ($role->is_owner && $permissions !== null) {
            throw RoleException::ownerPermissionsFrozen();
        }

        // An owner edits any role — including their own, which is the only
        // way the rename above ever happens. For everyone else, editing the
        // role you stand on is the shortest path from `roles.manage` to
        // whatever else you fancy.
        if (! $actor->isOwner() && (int) $role->getKey() === (int) $actor->merchant_role_id) {
            throw RoleException::cannotEditOwnRole();
        }

        if ($permissions !== null) {
            $granted = $this->catalogued($permissions);

            // Only the ADDITIONS are a grant. Checking the whole resulting
            // set instead would stop a limited `roles.manage` holder from so
            // much as renaming a role more powerful than themselves, while
            // adding nothing: removing a permission hands nobody anything.
            $held = $role->permissions ?? [];
            $added = array_values(array_filter(
                $granted,
                fn (Permission $permission) => ! in_array($permission->value, $held, true),
            ));

            $this->assertMayGrant($actor, $added);

            $role->permissions = $this->slugs($granted);
        }

        if (array_key_exists('name', $changes)) {
            // The slug never follows a rename: it is how RolePresetService
            // recognises the seeded roles (firstOrCreate on merchant_id +
            // slug), so a store that renamed "Staff" would be handed a
            // second one the next time its presets were provisioned.
            $role->name = trim($changes['name']);
        }

        if (array_key_exists('name_dv', $changes)) {
            $role->name_dv = $changes['name_dv'];
        }

        $role->save();

        return $role;
    }

    /**
     * There is no guard here against deleting the role you hold yourself:
     * holding it makes it a role in use, and the count below refuses it
     * with the sentence that actually helps.
     */
    public function delete(MerchantRole $role): void
    {
        if ($role->is_owner) {
            throw RoleException::ownerRoleUndeletable();
        }

        DB::transaction(function () use ($role): void {
            MerchantRole::query()->whereKey($role->getKey())->lockForUpdate()->first();

            $assigned = MerchantUser::query()->where('merchant_role_id', $role->getKey())->count();

            // Deactivated accounts count. They keep resolving in audit
            // trails and can be switched back on, and the foreign key would
            // refuse the delete anyway — this is the sentence a shopkeeper
            // can act on rather than a 500.
            if ($assigned > 0) {
                throw RoleException::inUse($assigned);
            }

            $role->delete();
        });
    }

    /**
     * Whether $actor may point a staff account at $role — the staff screen's
     * half of D5. Handing someone else a role is exactly as much of a grant
     * as writing the permissions into it: an invite goes to an address the
     * inviter chooses.
     */
    public function assertMayAssign(MerchantUser $actor, MerchantRole $role): void
    {
        $this->assertOwnMerchant($actor, (int) $role->merchant_id);

        if ($role->is_owner && ! $actor->isOwner()) {
            throw RoleException::ownerRoleNotDelegable();
        }

        $this->assertMayGrant($actor, array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission) => in_array($permission->value, $role->permissions ?? [], true),
        )));
    }

    /**
     * Every caller here already scoped its query to the signed-in
     * merchant, so this can only fire on a caller inside the API wiring one
     * store's actor to another's role — which is why it is a 500 and not a
     * refusal. Role ids are shared sequential integers; the cheapest place
     * to make that bug class unreachable is the layer that owns the rules.
     */
    private function assertOwnMerchant(MerchantUser $actor, int $merchantId): void
    {
        if ((int) $actor->merchant_id !== $merchantId) {
            throw new InvalidArgumentException('A merchant user may only manage roles belonging to their own merchant.');
        }
    }

    /**
     * The rule itself: nothing leaves your hands that was not in them. An
     * owner passes every time without a special case — the wildcard in
     * MerchantUser::can() answers true for the whole catalogue, which is
     * precisely what "may delegate anything" means.
     *
     * @param  list<Permission>  $granted
     */
    private function assertMayGrant(MerchantUser $actor, array $granted): void
    {
        $missing = array_values(array_filter(
            $granted,
            fn (Permission $permission) => ! $actor->can($permission),
        ));

        if ($missing !== []) {
            throw RoleException::permissionsNotHeld($missing);
        }
    }

    /**
     * Slugs in, catalogue members out — deduplicated and in CATALOGUE order,
     * so a stored set is canonical however the panel ordered its checkboxes.
     *
     * A slug outside the catalogue throws rather than being dropped: the
     * request validation has already refused it at the edge, so reaching
     * here means a caller inside the API is guessing, and a silently
     * discarded permission is a role that does less than it says.
     *
     * @param  list<string>  $slugs
     * @return list<Permission>
     */
    private function catalogued(array $slugs): array
    {
        foreach ($slugs as $slug) {
            if (! is_string($slug) || Permission::tryFrom($slug) === null) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown merchant permission [%s].',
                    is_string($slug) ? $slug : get_debug_type($slug),
                ));
            }
        }

        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission) => in_array($permission->value, $slugs, true),
        ));
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<string>
     */
    private function slugs(array $permissions): array
    {
        return array_map(fn (Permission $permission) => $permission->value, $permissions);
    }

    /**
     * Slugifies the name and makes it unique within the merchant by
     * suffixing -2, -3, … A name that slugifies to nothing (a pure Thaana
     * one, say) falls back to "role". Uniqueness is race-backed by the
     * unique index on (merchant_id, slug).
     */
    private function uniqueSlug(int $merchantId, string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'role';
        }

        $base = Str::limit($base, 70, '');

        $taken = MerchantRole::query()
            ->where('merchant_id', $merchantId)
            ->where('slug', 'like', $base.'%')
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        for ($i = 2; ; $i++) {
            $candidate = $base.'-'.$i;

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }
}
