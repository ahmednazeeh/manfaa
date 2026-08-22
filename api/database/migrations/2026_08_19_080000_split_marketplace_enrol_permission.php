<?php

use App\Domain\MerchantAccess\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applying to the marketplace becomes its own permission
 * (security audit 2026-08-19).
 *
 * `marketplace.manage` was granted to every role so that counter staff could
 * work the order queue — which is right. But the same permission also gated
 * ENROLMENT: applying to sell, uploading the company's registration and the
 * owner's identity papers, and submitting the lot for review. A cashier
 * could commit the business and send us its documents.
 *
 * Two jobs of very different weight had one key. `marketplace.enrol` is the
 * heavier one, and it goes to roles that already carry business-level
 * authority — owners and anyone who may edit the store profile — never to a
 * role that only sells.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enrol = Permission::MarketplaceEnrol->value;

        foreach (DB::table('merchant_roles')->get(['id', 'is_owner', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions) || in_array($enrol, $permissions, true)) {
                continue;
            }

            // Owners always; otherwise only a role already trusted with the
            // store's own profile, which is the same tier of decision.
            $eligible = (bool) $role->is_owner
                || in_array(Permission::ProfileEdit->value, $permissions, true);

            if (! $eligible) {
                continue;
            }

            $permissions[] = $enrol;

            DB::table('merchant_roles')
                ->where('id', $role->id)
                ->update(['permissions' => json_encode(array_values($permissions))]);
        }
    }

    public function down(): void
    {
        // Not reversed: removing it would lock an owner out of their own
        // application, and the grant is inert where no marketplace exists.
    }
};
