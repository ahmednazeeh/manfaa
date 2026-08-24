<?php

use App\Domain\MerchantAccess\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give existing roles the new `wallet.top_up` permission (owner, 2026-08-24).
 *
 * Every role on the platform was seeded before it existed, so without this
 * only owners (the wildcard) could fund a wallet. Granted to exactly the
 * roles that already hold `wallet.settle`: a role trusted to SPEND the
 * balance is the one that transfers the money in — that is the Manager
 * preset and any custom role a merchant widened to match. Staff, who hold
 * only `wallet.view`, get nothing. A merchant who disagrees can take it off
 * any role from the panel.
 *
 * Additive only — no role loses anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        $slug = Permission::WalletTopUp->value;
        $sibling = Permission::WalletSettle->value;

        foreach (DB::table('merchant_roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions)
                || ! in_array($sibling, $permissions, true)
                || in_array($slug, $permissions, true)) {
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
        // Deliberately NOT reversed: taking a permission back would silently
        // lock staff out of a form they had been using.
    }
};
