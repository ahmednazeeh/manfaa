<?php

namespace App\Models;

use App\Domain\MerchantAccess\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named permission set belonging to ONE merchant (PLAN §13b staff
 * permissions). Roles are per-merchant rather than global because the owner
 * asked for custom roles: "Cashier", "Shift lead" and "Accounts" mean
 * different things in different shops, and a shared table would let one
 * store's edit reach another's till.
 *
 * `is_owner` is the unbounded role — see MerchantUser::can(), which never
 * reads `permissions` for it. `is_system` marks the three presets every
 * merchant is seeded with, so the roles screen can refuse to let the store
 * delete the ground it stands on.
 */
class MerchantRole extends Model
{
    protected $guarded = [];

    /**
     * What this role actually grants, with the owner wildcard EXPANDED
     * against the catalogue (D3). The owner's stored list is empty on
     * purpose — its authority is the flag — so anything reading the column
     * raw would draw the most powerful role in the store holding nothing at
     * all, whether that is the roles screen's checkboxes or the panel's
     * `permissions.includes(…)`.
     *
     * Intersected with the catalogue, in catalogue order, so a slug left
     * behind by a deploy that removed a permission never reaches the wire.
     *
     * @return list<string>
     */
    public function resolvedPermissions(): array
    {
        if ($this->is_owner) {
            return Permission::values();
        }

        $held = $this->permissions ?? [];

        return array_values(array_filter(
            Permission::values(),
            fn (string $slug) => in_array($slug, $held, true),
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'permissions' => 'array',
            'is_owner' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }
}
