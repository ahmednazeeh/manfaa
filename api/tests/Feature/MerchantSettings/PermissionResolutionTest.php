<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Domain\MerchantAccess\Permission;
use App\Domain\MerchantAccess\PermissionGroup;
use App\Domain\MerchantAccess\RolePresetService;
use App\Domain\Onboarding\MerchantOtpService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// How a permission set is RESOLVED — the wildcard, the wire, the seeded
// presets. Where RoleMatrixTest asks which route answers to which slug, this
// asks what a role actually means once the catalogue moves under it.
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->roles = app(RolePresetService::class)->provision($this->merchant);
});

it('keeps an owner holding a permission the catalogue gained after the store signed up', function () {
    // The one property that makes the wildcard non-negotiable (§2.3). An
    // owner role stored as an enumerated list is written the day a store
    // signs up, and every deploy that adds a permission afterwards locks
    // that store out of the new screen until somebody hand-edits the role.
    //
    // A PHP enum cannot grow at runtime, so the deploy is simulated from the
    // other end: a role enumerating the catalogue AS IT WAS, one case short
    // of today's, standing next to the owner whose authority is the flag.
    $catalogue = Permission::values();
    $newest = end($catalogue);
    $asItWas = array_values(array_slice($catalogue, 0, -1));

    $stale = MerchantRole::query()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Everything, written down',
        'slug' => 'everything-written-down',
        'permissions' => $asItWas,
        'is_owner' => false,
        'is_system' => false,
    ]);
    $enumerated = MerchantUser::factory()->for($this->merchant)->withRole($stale)->create();

    expect($enumerated->can($newest))->toBeFalse()
        ->and($enumerated->resolvedPermissions())->toBe($asItWas)
        ->and($this->owner->can($newest))->toBeTrue()
        ->and($this->owner->role->permissions)->toBe([])
        ->and($this->owner->resolvedPermissions())->toBe($catalogue);

    foreach (Permission::cases() as $permission) {
        expect($this->owner->can($permission))->toBeTrue();
    }
});

it('drops a stored slug the catalogue no longer has instead of putting it on the wire', function () {
    // The mirror of the case above: a deploy that REMOVES a permission
    // leaves the slug behind in every role that held it, and a panel handed
    // one would render a checkbox for a permission nothing enforces.
    $orphaned = MerchantRole::query()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Left behind',
        'slug' => 'left-behind',
        'permissions' => ['transactions.view', 'rate.summon_the_kraken'],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $user = MerchantUser::factory()->for($this->merchant)->withRole($orphaned)->create();

    expect($orphaned->resolvedPermissions())->toBe(['transactions.view'])
        ->and($user->can('rate.summon_the_kraken'))->toBeFalse()
        ->and($user->can('transactions.view'))->toBeTrue();
});

it('sends /me the resolved set, with the owner wildcard expanded and no sentinel', function () {
    $body = $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/auth/me')
        ->assertOk()
        ->json('data');

    // D3: ship the wildcard as a sentinel and the panel's
    // permissions.includes('bank_account.update') returns false for the one
    // account it exists to protect.
    expect($body['permissions'])->toBe(Permission::values())
        ->and($body['permissions'])->not->toContain('*')
        ->and($body['permissions'])->toContain('bank_account.update')
        ->and($body['role']['is_owner'])->toBeTrue();

    $staff = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $staffBody = $this->actingAs($staff, 'merchant')
        ->getJson('/api/merchant/auth/me')
        ->assertOk()
        ->json('data');

    expect($staffBody['permissions'])->toEqualCanonicalizing(array_map(
        fn (Permission $permission) => $permission->value,
        RolePresetService::staffPermissions(),
    ))
        ->and($staffBody['role']['is_owner'])->toBeFalse();
});

it('publishes the catalogue grouped, so the roles screen renders no hardcoded copy', function () {
    $groups = $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/permissions')
        ->assertOk()
        ->json('data.groups');

    expect(array_column($groups, 'slug'))->toBe(array_map(
        fn (PermissionGroup $group) => $group->value,
        PermissionGroup::cases(),
    ));

    $published = [];

    foreach ($groups as $group) {
        expect($group['label'])->not->toBe($group['slug']);

        foreach ($group['permissions'] as $permission) {
            $published[] = $permission['slug'];

            // No raw snake_case reaches a screen: every slug carries a
            // sentence a shopkeeper can read.
            expect($permission['label'])->not->toContain('_')
                ->and($permission['group'])->toBe($group['slug']);
        }
    }

    expect($published)->toEqualCanonicalizing(Permission::values());
});

it('gives every merchant its three roles and an owner standing on one, straight out of signup', function () {
    $sms = new class implements SmsSender
    {
        /** @var list<array{phone: string, message: string}> */
        public array $sent = [];

        public function send(string $phone, string $message): void
        {
            $this->sent[] = ['phone' => $phone, 'message' => $message];
        }
    };
    $this->app->instance(SmsSender::class, $sms);

    $service = app(MerchantOtpService::class);
    $service->request('+9607719001');

    $sent = $sms->sent;
    preg_match('/\b(\d{6})\b/', end($sent)['message'], $matches);

    $owner = $service->register(
        $service->verify('+9607719001', $matches[1]),
        'Seeded Store',
        'seeded@example.com',
        'a-strong-password',
    );

    $roles = MerchantRole::query()->where('merchant_id', $owner->merchant_id)->orderBy('id')->get();

    expect($roles->pluck('slug')->all())->toBe([
        RolePresetService::OWNER,
        RolePresetService::MANAGER,
        RolePresetService::STAFF,
    ])
        ->and($roles->every(fn (MerchantRole $role) => $role->is_system))->toBeTrue()
        // Every preset carries a Thaana name: role labels used to be i18n
        // keys, and a custom name cannot be one (D7).
        ->and($roles->every(fn (MerchantRole $role) => $role->name_dv !== null && $role->name_dv !== ''))->toBeTrue()
        ->and($owner->isOwner())->toBeTrue()
        ->and($owner->merchant_role_id)->toBe($roles->firstWhere('slug', RolePresetService::OWNER)->id)
        ->and($owner->resolvedPermissions())->toBe(Permission::values());

    // Idempotent: provisioning again keeps the store's own edits, so a
    // second signup-shaped call never hands a widened Manager back its
    // preset.
    $manager = $roles->firstWhere('slug', RolePresetService::MANAGER);
    $manager->update(['permissions' => ['transactions.view']]);

    app(RolePresetService::class)->provision($owner->merchant);

    expect(MerchantRole::query()->where('merchant_id', $owner->merchant_id)->count())->toBe(3)
        ->and($manager->refresh()->permissions)->toBe(['transactions.view']);
});

it('seeds Staff with every surface that carried no gate before permissions existed', function () {
    // Trap 3: each of these is a NARROWING. Miss one and every till in the
    // field loses its main screen the day this deploys.
    $staffRole = $this->roles[RolePresetService::STAFF];

    expect($staffRole->permissions)->toBe([
        'credits.create',
        'customers.lookup',
        'transactions.view',
        'rate.view',
        'promotions.view',
        'product_categories.view',
        'settlements.view',
        'settlements.preview',
        'wallet.view',
        // Added deliberately, and later than the rest: the order queue is
        // counter work — the people who pick and hand over marketplace
        // orders are the same staff standing at the till. What they still
        // cannot do is `marketplace.enrol`, which is Manager and above:
        // committing the business to selling online, and uploading the
        // owner's identity papers to do it, is not a cashier's call.
        'marketplace.manage',
    ]);

    // Staff is a strict subset of Manager, and Manager reaches none of the
    // account surfaces the owner tier kept to itself.
    $managerHolds = $this->roles[RolePresetService::MANAGER]->permissions;

    expect(array_diff($staffRole->permissions, $managerHolds))->toBe([])
        ->and(array_intersect($managerHolds, [
            'bank_account.view',
            'bank_account.update',
            'staff.view',
            'staff.invite',
            'staff.edit',
            'roles.view',
            'roles.manage',
            'preferences.update',
            'api_credentials.view',
            'api_credentials.create',
            'api_credentials.revoke',
            'profile.edit',
            'branding.update',
            'setup.view',
            'setup.edit',
            'setup.submit',
        ]))->toBe([]);
});

it('still reaches every previously ungated surface as seeded Staff', function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant->update(['validation_window_days' => 3, 'min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    MerchantWallet::query()->create([
        'merchant_id' => $this->merchant->id,
        'balance_laari' => 0,
        'currency' => 'MVR',
    ]);
    Customer::factory()->create(['customer_code' => '482917']);

    $staff = MerchantUser::factory()->for($this->merchant)->staff()->create();

    $this->actingAs($staff, 'merchant');

    $this->getJson('/api/merchant/customers/lookup?code=482917')->assertOk();
    $this->getJson('/api/merchant/transactions')->assertOk();
    $this->getJson('/api/merchant/rate')->assertOk();
    $this->getJson('/api/merchant/promotions')->assertOk();
    $this->getJson('/api/merchant/product-categories')->assertOk();
    $this->getJson('/api/merchant/settlements')->assertOk();
    $this->getJson('/api/merchant/outstanding')->assertOk();
    $this->getJson('/api/merchant/wallet')->assertOk();

    // The till's whole reason to be logged in.
    $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-NARROWING-1',
        'eligible_amount' => 125000,
        'sale_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])->assertCreated();

    // The preview claims nothing, so it stayed open too — 422 here is the
    // empty-selection answer, not a refusal.
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')->assertStatus(422);
});
