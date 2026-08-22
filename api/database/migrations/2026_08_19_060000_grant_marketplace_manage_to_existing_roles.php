<?php

use App\Domain\MerchantAccess\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give existing roles the marketplace permission they never got
 * (owner report 2026-08-19: "vendor KYB was already approved" and still no
 * marketplace anywhere).
 *
 * `marketplace.manage` was introduced with the marketplace, but every role
 * on the platform had been seeded BEFORE it existed. So an approved vendor
 * had nobody who could work its order queue: the app hid the tab, the panel
 * hid the menu, and the API answered 403 — three symptoms of one missing
 * row, and none of them said so.
 *
 * Granted to owners and managers always, and to staff because the order
 * queue is counter work: the people who pick and hand over an order are the
 * ones who need it. A merchant who disagrees can take it off any role from
 * the panel.
 *
 * Additive only — no role loses anything, and a role that somehow already
 * has it is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $slug = Permission::MarketplaceManage->value;

        foreach (DB::table('merchant_roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions) || in_array($slug, $permissions, true)) {
                continue;
            }

            $permissions[] = $slug;

            DB::table('merchant_roles')
                ->where('id', $role->id)
                ->update(['permissions' => json_encode(array_values($permissions))]);
        }
    }

    public function down(): void
    {
        // Deliberately NOT reversed. Taking a permission back would silently
        // lock staff out of a queue they had been working, and the grant is
        // harmless where no marketplace exists.
    }
};
