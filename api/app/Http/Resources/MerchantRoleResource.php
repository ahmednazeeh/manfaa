<?php

namespace App\Http\Resources;

use App\Models\MerchantRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of a merchant's roles, as the Settings › Roles screen sees it.
 *
 * `permissions` is the RESOLVED set: the owner role stores an empty list
 * because its authority is the flag and not a list (§2.3), and a screen
 * that rendered the column raw would draw the most powerful role in the
 * store with every box unticked. The two flags travel with it so the panel
 * can grey out what it must not offer — `is_owner` is frozen apart from its
 * name, and a role with staff on it cannot be deleted.
 *
 * @mixin MerchantRole
 */
class MerchantRoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_dv' => $this->name_dv,
            // Stable per merchant and never rewritten by a rename — the
            // seeded roles are recognised by it.
            'slug' => $this->slug,
            'is_owner' => (bool) $this->is_owner,
            'is_system' => (bool) $this->is_system,
            'permissions' => $this->resource->resolvedPermissions(),
            // How many accounts stand on this role: the roles list shows it,
            // and it is the reason a delete is refused.
            'staff_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The role as it appears NEXT TO a person — on the staff list and on
     * the signed-in account. Just enough to print it and to know it is the
     * frozen one; the permission set belongs to the roles screen, and
     * repeating it against every staff row would ship the whole catalogue
     * once per cashier.
     *
     * Null where a role somehow is not set: nothing has authority without
     * one, so the panel renders the gap rather than inventing a name.
     *
     * @return array<string, mixed>|null
     */
    public static function summary(?MerchantRole $role): ?array
    {
        return $role === null ? null : [
            'id' => $role->id,
            'name' => $role->name,
            'name_dv' => $role->name_dv,
            'is_owner' => (bool) $role->is_owner,
        ];
    }
}
