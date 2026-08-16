<?php

use App\Domain\MerchantAccess\RolePresetService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fine-grained merchant staff permissions (PLAN §13b): the ranked
 * `staff < manager < owner` string on merchant_users becomes a reference to
 * a per-merchant ROLE carrying a permission set.
 *
 * The catalogue itself is code (App\Domain\MerchantAccess\Permission); this
 * table stores only which of it each role holds — jsonb with an 'array'
 * cast, following api_credentials.abilities.
 *
 * Behaviour-preserving: each merchant is seeded the three preset roles with
 * exactly today's powers, and every existing user is repointed at the
 * preset matching the tier string they held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->index()->constrained();
            $table->string('name');
            // Custom role names cannot be i18n keys, so a store that wants
            // its roles read in Thaana carries its own translation (D7).
            $table->string('name_dv')->nullable();
            $table->string('slug');
            $table->jsonb('permissions');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['merchant_id', 'slug']);
        });

        Schema::table('merchant_users', function (Blueprint $table) {
            // Nullable so the backfill below can run against rows that
            // already exist; a null role is refused by every gate
            // (MerchantUser::can fails closed), so it is never an opening.
            $table->foreignId('merchant_role_id')->nullable()->index()->constrained();
        });

        $this->seedRolesAndRepointUsers();

        DB::statement('ALTER TABLE merchant_users DROP CONSTRAINT merchant_users_role_check');

        Schema::table('merchant_users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->string('role')->nullable();
        });

        // Collapse each role back onto the tier that can express it. A
        // CUSTOM role has no tier — whatever it held, the closed set
        // {owner, manager, staff} cannot say it — so it falls to `staff`,
        // the direction that hands nobody more power than they had.
        DB::statement(<<<'SQL'
            UPDATE merchant_users
               SET role = CASE
                   WHEN merchant_roles.is_owner THEN 'owner'
                   WHEN merchant_roles.slug = 'manager' THEN 'manager'
                   ELSE 'staff'
               END
              FROM merchant_roles
             WHERE merchant_roles.id = merchant_users.merchant_role_id
        SQL);

        DB::table('merchant_users')->whereNull('role')->update(['role' => 'staff']);

        DB::statement("ALTER TABLE merchant_users ALTER COLUMN role SET DEFAULT 'staff'");
        DB::statement('ALTER TABLE merchant_users ALTER COLUMN role SET NOT NULL');
        DB::statement("ALTER TABLE merchant_users ADD CONSTRAINT merchant_users_role_check CHECK (role IN ('owner', 'manager', 'staff'))");

        Schema::table('merchant_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merchant_role_id');
        });

        Schema::dropIfExists('merchant_roles');
    }

    /**
     * Seeds every merchant's preset roles and maps its users onto them.
     *
     * Raw queries throughout: this runs against the schema as it stands
     * mid-migration, where merchant_users still has `role` and the Eloquent
     * model no longer knows about it.
     */
    private function seedRolesAndRepointUsers(): void
    {
        $now = CarbonImmutable::now('UTC');

        foreach (DB::table('merchants')->orderBy('id')->pluck('id') as $merchantId) {
            foreach (RolePresetService::presets() as $preset) {
                $roleId = DB::table('merchant_roles')->insertGetId([
                    'merchant_id' => $merchantId,
                    'name' => $preset['name'],
                    'name_dv' => $preset['name_dv'],
                    'slug' => $preset['slug'],
                    'permissions' => json_encode($preset['permissions']),
                    'is_owner' => $preset['is_owner'],
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $query = DB::table('merchant_users')->where('merchant_id', $merchantId);

                if ($preset['slug'] === RolePresetService::STAFF) {
                    // Anything outside the three known tiers lands on Staff
                    // — the floor `hasRoleAtLeast` already gave an
                    // unrecognised string, which ranked below everything.
                    $query->whereNotIn('role', [RolePresetService::OWNER, RolePresetService::MANAGER]);
                } else {
                    $query->where('role', $preset['slug']);
                }

                $query->update(['merchant_role_id' => $roleId]);
            }
        }
    }
};
