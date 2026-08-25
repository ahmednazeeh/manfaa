<?php

namespace App\Models;

use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileTokenSubject;
use Database\Factories\MerchantUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['merchant_id', 'name', 'email', 'password', 'merchant_role_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class MerchantUser extends Authenticatable implements MobileTokenSubject
{
    /** @use HasFactory<MerchantUserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * A deactivated staff member may not use the merchant app.
     *
     * `!== false`, matching MerchantAuthController::login exactly: a
     * not-yet-migrated is_active column reads null, and null must mean "not
     * deactivated" rather than locking every existing account out of the app
     * on the deploy that ships this.
     */
    public function mayUseMobileApp(): bool
    {
        return $this->is_active !== false;
    }

    /**
     * Whether this account holds $permission (PLAN §13b staff permissions).
     * The single answer every gate asks — the route middleware, the finer
     * in-controller checks, and the panel by way of resolvedPermissions().
     *
     * An `is_owner` role answers true for the whole catalogue and is never
     * consulted for a stored list: a permission added by the next deploy
     * must reach owners the moment it ships, or every store would sit
     * locked out of a new screen until someone hand-edited its owner role.
     *
     * Everything ambiguous is a refusal. A slug outside the catalogue is a
     * typo in the caller, not a request for a future permission — the
     * catalogue is code and ships with the check — so it denies even an
     * owner, and denying everyone is how a typo gets noticed instead of
     * quietly skewing who may act. A user with no role, or one pointing at
     * ANOTHER merchant's role, is refused outright: cross-tenant ids are
     * the bug class a shared integer key invites, and the resolution path
     * is the one place that can make it structurally unreachable.
     *
     * Signature is Laravel's Authorizable contract, which this model
     * inherits from Foundation\Auth\User and cannot narrow; the accepted
     * argument is a Permission or its slug.
     *
     * @param  Permission|string  $abilities
     * @param  mixed  $arguments
     */
    public function can($abilities, $arguments = []): bool
    {
        $permission = $abilities instanceof Permission
            ? $abilities
            : (is_string($abilities) ? Permission::tryFrom($abilities) : null);

        if ($permission === null) {
            return false;
        }

        $role = $this->role;

        if ($role === null || (int) $role->merchant_id !== (int) $this->merchant_id) {
            return false;
        }

        if ($role->is_owner) {
            return true;
        }

        return in_array($permission->value, $role->permissions ?? [], true);
    }

    /**
     * Whether this account holds the store's unbounded OWNER role — the
     * immutable FLAG on the role, never a permission (D4).
     *
     * The two questions are deliberately different. `can()` asks what
     * someone may do and a custom role can answer yes to any of it; this
     * asks whether they are one of the accounts a store cannot be left
     * without, and whether they may hand that standing to anyone else. Key
     * either of those on `staff.edit` and a store could demote its last
     * owner the moment some custom role happened to hold it.
     *
     * Fails closed on the same cross-tenant role the permission check
     * refuses: a role belonging to another merchant is not this store's
     * owner by any reading.
     */
    public function isOwner(): bool
    {
        $role = $this->role;

        return $role !== null
            && (int) $role->merchant_id === (int) $this->merchant_id
            && (bool) $role->is_owner;
    }

    /**
     * The flat permission set this account actually holds, with the owner
     * wildcard EXPANDED against the catalogue (D3). The panel reads a set,
     * never a role name, and shipping it a sentinel instead would make
     * `permissions.includes('bank_account.update')` false for the very
     * account the wildcard exists to protect.
     *
     * The expansion itself is the role's own question
     * (MerchantRole::resolvedPermissions); what this adds is the same
     * fail-closed tenancy guard can() applies, so a cross-tenant role sends
     * the panel an empty set rather than someone else's authority.
     *
     * @return list<string>
     */
    public function resolvedPermissions(): array
    {
        $role = $this->role;

        if ($role === null || (int) $role->merchant_id !== (int) $this->merchant_id) {
            return [];
        }

        return $role->resolvedPermissions();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'merchant_role_id' => 'integer',
            'password' => 'hashed',
            'is_active' => 'boolean',
            // The guided-setup tasklist is anchored per PERSON (owner,
            // 2026-08-25): when their five days started, whether they put
            // it away, whether they finished the walkthrough. Expiry is
            // derived from the anchor on every read — see OnboardingGuide.
            'onboarding_started_at' => 'immutable_datetime',
            'onboarding_skipped_at' => 'immutable_datetime',
            'onboarding_tour_completed_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(MerchantRole::class, 'merchant_role_id');
    }
}
