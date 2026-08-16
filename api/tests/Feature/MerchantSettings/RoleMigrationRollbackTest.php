<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * This box rolls a bad deploy BACK; it never rebuilds the database (a
 * migrate:fresh here destroyed production once already). So `down()` is not
 * decoration — it is the recovery path, and the only way to know it works is
 * to walk it.
 *
 * Everything below runs inside the test's own transaction, DDL included:
 * Postgres rolls schema changes back with everything else, so the suite's
 * schema is exactly as it was the moment this test ends.
 */
function merchantRolesMigration(): Migration
{
    $paths = glob(database_path('migrations/*_create_merchant_roles_table.php'));

    expect($paths)->toHaveCount(1);

    /** @var Migration $migration */
    $migration = require $paths[0];

    return $migration;
}

it('rolls the permissions migration back onto the tier column it replaced, and forward again', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $manager = MerchantUser::factory()->for($merchant)->manager()->create();
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();

    $custom = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Shift lead',
        'slug' => 'shift-lead',
        'permissions' => ['transactions.view', 'bank_account.update'],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $lead = MerchantUser::factory()->for($merchant)->withRole($custom)->create();

    $migration = merchantRolesMigration();
    $migration->down();

    expect(Schema::hasTable('merchant_roles'))->toBeFalse()
        ->and(Schema::hasColumn('merchant_users', 'merchant_role_id'))->toBeFalse()
        ->and(Schema::hasColumn('merchant_users', 'role'))->toBeTrue();

    $tiers = DB::table('merchant_users')
        ->whereIn('id', [$owner->id, $manager->id, $staff->id, $lead->id])
        ->pluck('role', 'id');

    expect($tiers[$owner->id])->toBe('owner')
        ->and($tiers[$manager->id])->toBe('manager')
        ->and($tiers[$staff->id])->toBe('staff')
        // A custom role has no tier — the closed set {owner, manager, staff}
        // cannot say what "Shift lead" held — so it lands on the floor, the
        // direction that hands nobody more power than they had.
        ->and($tiers[$lead->id])->toBe('staff');

    // The CHECK the forward migration dropped by name is back, with the same
    // name and the same three tiers. Wrapped in its own transaction so the
    // refused statement rolls back to a savepoint instead of poisoning the
    // rest of the test.
    expect(fn () => DB::transaction(fn () => DB::table('merchant_users')
        ->where('id', $owner->id)
        ->update(['role' => 'supervisor'])))
        ->toThrow(QueryException::class);

    expect(DB::table('merchant_users')->where('id', $owner->id)->value('role'))->toBe('owner');

    $migration->up();

    expect(Schema::hasTable('merchant_roles'))->toBeTrue()
        ->and(Schema::hasColumn('merchant_users', 'role'))->toBeFalse();

    // Rolling forward re-seeds and re-points, so the trip is
    // behaviour-preserving for every tier the round trip could express. What
    // it cannot restore is the custom role itself — which is the honest
    // shape of a rollback, and the reason down() collapses downward.
    expect(MerchantRole::query()->where('merchant_id', $merchant->id)->orderBy('id')->pluck('slug')->all())
        ->toBe(['owner', 'manager', 'staff'])
        ->and(MerchantUser::query()->findOrFail($owner->id)->isOwner())->toBeTrue()
        ->and(MerchantUser::query()->findOrFail($manager->id)->role->slug)->toBe('manager')
        ->and(MerchantUser::query()->findOrFail($staff->id)->role->slug)->toBe('staff')
        ->and(MerchantUser::query()->findOrFail($lead->id)->role->slug)->toBe('staff');
});
