<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\MerchantAccess\RolePresetService;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Merchant app MR5 — the settings estate on mobile/v1: profile, branches,
 * bank account, staff, roles + the served permission catalogue, preferences,
 * the product-category writes, the promotion lifecycle and the standing-rate
 * change. All mounted from the SAME web controllers
 * (routes/api/merchant-settings.php, product-categories.php, promotions.php,
 * webhooks.php) behind auth:sanctum + mobile.token:merchant, permission
 * FIRST on every route, with the web's exact approval strengths: default
 * EnsureMerchantApproved on the review-shaping writes, none on the account
 * surface, `:trading` on the commercial offer. Refusals leave in the one
 * mobile envelope.
 */

/** Bearer headers for a mobile merchant token issued to $user. */
function settingsHeadersMr5(MerchantUser $user): array
{
    $token = app(MobileTokenService::class)
        ->issue($user, MobileAudience::Merchant, 'Phone')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/** An active store whose standing rate can price promotions and changes. */
function settingsRatedMerchantMr5(): Merchant
{
    $merchant = Merchant::factory()->create(['min_eligible_laari' => 5000]);

    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    return $merchant;
}

/**
 * Every route of the estate with the permission slug that guards it — the
 * gating loop and both refusal loops walk this one table, so a
 * twenty-fifth route cannot be mounted without a test noticing the map
 * changed.
 *
 * @return list<array{string, string, string}> [method, uri, permission]
 */
function settingsRoutesMr5(): array
{
    return [
        ['getJson', '/api/mobile/v1/merchant/profile', Permission::ProfileView->value],
        ['patchJson', '/api/mobile/v1/merchant/profile', Permission::ProfileEdit->value],
        ['getJson', '/api/mobile/v1/merchant/branches', Permission::BranchesView->value],
        ['postJson', '/api/mobile/v1/merchant/branches', Permission::BranchesCreate->value],
        ['patchJson', '/api/mobile/v1/merchant/branches/1', Permission::BranchesEdit->value],
        ['deleteJson', '/api/mobile/v1/merchant/branches/1', Permission::BranchesDelete->value],
        ['getJson', '/api/mobile/v1/merchant/bank-account', Permission::BankAccountView->value],
        ['patchJson', '/api/mobile/v1/merchant/bank-account', Permission::BankAccountUpdate->value],
        ['getJson', '/api/mobile/v1/merchant/staff', Permission::StaffView->value],
        ['postJson', '/api/mobile/v1/merchant/staff', Permission::StaffInvite->value],
        ['patchJson', '/api/mobile/v1/merchant/staff/1', Permission::StaffEdit->value],
        ['getJson', '/api/mobile/v1/merchant/permissions', Permission::RolesView->value],
        ['getJson', '/api/mobile/v1/merchant/roles', Permission::RolesView->value],
        ['postJson', '/api/mobile/v1/merchant/roles', Permission::RolesManage->value],
        ['patchJson', '/api/mobile/v1/merchant/roles/1', Permission::RolesManage->value],
        ['deleteJson', '/api/mobile/v1/merchant/roles/1', Permission::RolesManage->value],
        ['patchJson', '/api/mobile/v1/merchant/preferences', Permission::PreferencesUpdate->value],
        ['postJson', '/api/mobile/v1/merchant/product-categories', Permission::ProductCategoriesCreate->value],
        ['patchJson', '/api/mobile/v1/merchant/product-categories/1', Permission::ProductCategoriesEdit->value],
        ['getJson', '/api/mobile/v1/merchant/promotions', Permission::PromotionsView->value],
        ['postJson', '/api/mobile/v1/merchant/promotions', Permission::PromotionsCreate->value],
        ['postJson', '/api/mobile/v1/merchant/promotions/1/publish', Permission::PromotionsPublish->value],
        ['postJson', '/api/mobile/v1/merchant/promotions/1/cancel', Permission::PromotionsCancel->value],
        ['postJson', '/api/mobile/v1/merchant/rate', Permission::RateUpdate->value],
    ];
}

// ------------------------------------------------------------------- authz

it('answers unauthenticated with the enveloped 401 on every settings route', function () {
    // The live smoke's local twin: the routes exist before credentials do,
    // and the refusal is the envelope, never a redirect or a 404.
    foreach (settingsRoutesMr5() as [$method, $uri]) {
        $response = $this->{$method}($uri)->assertStatus(401);

        expect($response->json('error.code'))->toBe('unauthenticated');
    }
});

it('refuses a customer token on every settings route', function () {
    $customer = Customer::factory()->create();
    $token = app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Customer, 'Phone')->plainTextToken;

    foreach (settingsRoutesMr5() as [$method, $uri]) {
        app('auth')->forgetGuards();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->{$method}($uri)
            ->assertStatus(401);

        expect($response->json('error.code'))->toBe('unauthenticated');
    }
});

it('gates every route on its own slug, before the store status can answer', function () {
    // A DRAFT store on purpose: were an approval gate ahead of the
    // permission check, the gated writes would answer 409 store_not_approved
    // (or store_not_trading) — telling an account that may not act how the
    // store stands. Permission first means 403 everywhere.
    $merchant = Merchant::factory()->create(['status' => 'draft']);
    $role = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Till only',
        'slug' => 'till-only-'.$merchant->id,
        'permissions' => [Permission::TransactionsView->value],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $user = MerchantUser::factory()->for($merchant)->withRole($role)->create();
    $headers = settingsHeadersMr5($user);

    foreach (settingsRoutesMr5() as [$method, $uri, $permission]) {
        app('auth')->forgetGuards();

        $response = $this->withHeaders($headers)
            ->{$method}($uri)
            ->assertStatus(403);

        // Enveloped, and naming WHICH permission is missing.
        expect($response->json('error.code'))->toBe('permission_required');
        expect($response->json('error.meta.permission'))->toBe($permission);
    }
});

// ----------------------------------------------------------------- profile

it('shows and edits the profile, keeping the support-phone convention', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Original Name',
        'contact_phone' => '+9607000001',
    ]);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    // Materialised at creation already: blank support copies contact
    // (Merchant::booted() — the shipped owner convention).
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/profile')
        ->assertOk()
        ->assertJsonPath('data.name', 'Original Name')
        ->assertJsonPath('data.contact_phone', '+9607000001')
        ->assertJsonPath('data.support_phone', '+9607000001');

    // A matching copy FOLLOWS a contact change — "same as contact" is a
    // comparison, not a stored null, so it cannot go stale.
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['contact_phone' => '+9609998877'])
        ->assertOk()
        ->assertJsonPath('data.support_phone', '+9609998877');

    // An explicitly distinct support line is respected and stays put…
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['support_phone' => '+9603334455'])
        ->assertOk();

    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['contact_phone' => '+9601112233'])
        ->assertOk()
        ->assertJsonPath('data.contact_phone', '+9601112233')
        ->assertJsonPath('data.support_phone', '+9603334455');

    // …and untying it again (support = null) re-materialises the contact.
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['support_phone' => null])
        ->assertOk()
        ->assertJsonPath('data.support_phone', '+9601112233');

    // The ordinary identity edit rides the same PATCH; the slug never moves.
    $slugBefore = $merchant->refresh()->slug;

    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['name' => 'Chai House', 'channel' => 'both'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Chai House')
        ->assertJsonPath('data.channel', 'both');

    expect($merchant->refresh()->slug)->toBe($slugBefore)
        ->and($merchant->support_phone)->toBe('+9601112233');
});

it('keeps the profile readable while the store waits, but refuses the write', function () {
    $merchant = Merchant::factory()->create(['status' => 'pending_review']);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    // Reads stay open — the More screen renders while the queue decides.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/profile')
        ->assertOk();

    // The write is the wizard conversation: 409 store_not_approved, in the
    // envelope, exactly as the web mount answers.
    $response = $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['name' => 'Rewritten Mid-Review'])
        ->assertStatus(409);

    expect($response->json('error.code'))->toBe('store_not_approved');
    expect($merchant->refresh()->name)->not->toBe('Rewritten Mid-Review');
});

// ---------------------------------------------------------------- branches

it('creates, edits and deletes a branch, and refuses to delete history', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    $branchId = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/branches', [
            'name' => 'Hulhumalé',
            'address' => 'Nirolhu Magu',
            'lat' => 4.2105,
            'lng' => 73.5409,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Hulhumalé')
        ->json('data.id');

    $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/branches/{$branchId}", ['name' => 'Hulhumalé Phase 2'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Hulhumalé Phase 2');

    // The coordinate pair stays a pair: unpairing is a validation refusal,
    // in the envelope.
    $response = $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/branches/{$branchId}", ['lat' => null])
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed');

    // A referenced branch is history that must keep resolving: 409
    // branch_referenced, and the row survives.
    $referenced = MerchantBranch::factory()->for($merchant)->create();
    Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $referenced->id,
        'customer_id' => Customer::factory()->create()->id,
    ]);

    $refusal = $this->withHeaders($headers)
        ->deleteJson("/api/mobile/v1/merchant/branches/{$referenced->id}")
        ->assertStatus(409);

    expect($refusal->json('error.code'))->toBe('branch_referenced');
    expect(MerchantBranch::query()->whereKey($referenced->id)->exists())->toBeTrue();

    // The unreferenced one deletes fine.
    $this->withHeaders($headers)
        ->deleteJson("/api/mobile/v1/merchant/branches/{$branchId}")
        ->assertNoContent();
});

// ------------------------------------------------------------ bank account

it('shows and repoints the bank identity as one atomic triple', function () {
    $merchant = Merchant::factory()->create([
        'bank_name' => 'bml',
        'bank_account' => '7701234567890',
        'bank_account_name' => 'Chai House Pvt Ltd',
    ]);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/bank-account')
        ->assertOk()
        ->assertJsonPath('data.bank_name', 'bml')
        ->assertJsonPath('data.bank_account', '7701234567890');

    // All three fields together, or nothing — a half-updated identity
    // mismatches every payment.
    $partial = $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/bank-account', ['bank_account' => '9900000000001'])
        ->assertStatus(422);

    expect($partial->json('error.code'))->toBe('validation_failed');

    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/bank-account', [
            'bank_name' => 'mib',
            'bank_account' => '9900000000001',
            'bank_account_name' => 'Chai House Pvt Ltd',
        ])
        ->assertOk()
        ->assertJsonPath('data.bank_name', 'mib')
        ->assertJsonPath('data.bank_account', '9900000000001');
});

// ------------------------------------------------------------------- staff

it('invites staff (temp password shown once), reassigns and toggles them', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $roles = app(RolePresetService::class)->provision($merchant);
    $headers = settingsHeadersMr5($owner);

    $response = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/staff', [
            'name' => 'New Cashier',
            'email' => 'cashier.mr5@example.com',
            'merchant_role_id' => $roles[RolePresetService::STAFF]->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Cashier')
        ->assertJsonPath('data.role.name', 'Staff')
        ->assertJsonPath('data.is_active', true);

    // Returned exactly once, on creation — the app's one-time reveal sheet
    // holds the only copy that will ever exist.
    $tempPassword = $response->json('temp_password');
    expect($tempPassword)->toBeString()->and(strlen($tempPassword))->toBeGreaterThanOrEqual(20);

    $staffId = $response->json('data.id');

    // Role reassign, then the active toggle, both through the same PATCH.
    $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/staff/{$staffId}", [
            'merchant_role_id' => $roles[RolePresetService::MANAGER]->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.role.name', 'Manager');

    $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/staff/{$staffId}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/staff/{$staffId}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('never removes the last active owner, in the envelope', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    // The guard runs before the self-targeting checks: a store with zero
    // active owners is locked out of its settings permanently, whoever
    // pulled the switch.
    $response = $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/staff/{$owner->id}", ['is_active' => false])
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed');
    expect($response->json('error.message'))->toContain('last active owner');
    expect($owner->refresh()->is_active)->toBeTrue();
});

// ------------------------------------------------------------------- roles

it('serves the permission catalogue and runs the role lifecycle', function () {
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    app(RolePresetService::class)->provision($merchant);
    $headers = settingsHeadersMr5($owner);

    // The catalogue is SERVED (D8): the checkbox grid renders the server's
    // groups and wording, so a permission shipped after this app build
    // still gets its checkbox.
    $catalogue = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/permissions')
        ->assertOk();

    $slugs = collect($catalogue->json('data.groups'))
        ->flatMap(fn (array $group) => array_column($group['permissions'], 'slug'));

    expect($slugs->all())->toContain(Permission::StaffInvite->value)
        ->toContain(Permission::SettlementsView->value)
        ->toContain(Permission::RolesManage->value);

    // The list carries the assignment count each card shows — the reason a
    // delete gets refused.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/roles')
        ->assertOk()
        ->assertJsonPath('data.0.staff_count', 1);

    $roleId = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/roles', [
            'name' => 'Auditor',
            'permissions' => [Permission::TransactionsView->value, Permission::SettlementsView->value],
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_owner', false)
        ->assertJsonPath('data.permissions', [Permission::TransactionsView->value, Permission::SettlementsView->value])
        ->json('data.id');

    $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/roles/{$roleId}", ['name' => 'Bookkeeper'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bookkeeper');

    // Unassigned, so it deletes cleanly.
    $this->withHeaders($headers)
        ->deleteJson("/api/mobile/v1/merchant/roles/{$roleId}")
        ->assertNoContent();
});

it('enforces the delegation rules with the coded refusals the screen names', function () {
    $merchant = Merchant::factory()->create();
    MerchantUser::factory()->for($merchant)->owner()->create();
    $roles = app(RolePresetService::class)->provision($merchant);

    // A deliberately partial delegate: runs the roles/staff screens, holds
    // nothing that touches the money.
    $supervisorRole = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Supervisor',
        'slug' => 'supervisor',
        'permissions' => [
            Permission::TransactionsView->value,
            Permission::StaffView->value,
            Permission::StaffInvite->value,
            Permission::StaffEdit->value,
            Permission::RolesView->value,
            Permission::RolesManage->value,
        ],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $supervisor = MerchantUser::factory()->for($merchant)->withRole($supervisorRole)->create();
    $supervisorHeaders = settingsHeadersMr5($supervisor);

    // D5: nothing leaves your hands that was not in them. The refusal NAMES
    // the slugs, in meta, so the app points at the checkbox.
    $delegation = $this->withHeaders($supervisorHeaders)
        ->postJson('/api/mobile/v1/merchant/roles', [
            'name' => 'Treasurer',
            'permissions' => [Permission::TransactionsView->value, Permission::BankAccountUpdate->value],
        ])
        ->assertStatus(403);

    expect($delegation->json('error.code'))->toBe('permission_not_held');
    expect($delegation->json('error.meta.permissions'))->toBe([Permission::BankAccountUpdate->value]);

    // The owner role is frozen (D9): renameable, permissions un-editable —
    // even by an owner.
    $ownerHeaders = settingsHeadersMr5(
        MerchantUser::factory()->for($merchant)->owner()->create(),
    );
    $ownerRoleId = $roles[RolePresetService::OWNER]->id;

    $frozen = $this->withHeaders($ownerHeaders)
        ->patchJson("/api/mobile/v1/merchant/roles/{$ownerRoleId}", [
            'permissions' => [Permission::TransactionsView->value],
        ])
        ->assertStatus(409);

    expect($frozen->json('error.code'))->toBe('owner_role_frozen');

    // A role still assigned refuses deletion with the count the sentence
    // needs — the supervisor stands on it.
    $inUse = $this->withHeaders($ownerHeaders)
        ->deleteJson("/api/mobile/v1/merchant/roles/{$supervisorRole->id}")
        ->assertStatus(409);

    expect($inUse->json('error.code'))->toBe('role_in_use');
    expect($inUse->json('error.meta.staff_count'))->toBe(1);
    expect(MerchantRole::query()->whereKey($supervisorRole->id)->exists())->toBeTrue();
});

// ------------------------------------------------------------- preferences

it('updates preferences within the platform bounds', function () {
    $merchant = Merchant::factory()->create([
        'min_eligible_laari' => 5000,
        'validation_window_days' => 3,
    ]);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    // Tightening is the merchant's to do freely; the response carries the
    // admin-governed ceiling so the form renders the real bound.
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/preferences', [
            'min_eligible_laari' => 2500,
            'validation_window_days' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('data.min_eligible_laari', 2500)
        ->assertJsonPath('data.validation_window_days', 2)
        ->assertJsonPath('data.validation_window_max_days', 3);

    // Raising the window past the platform ceiling is the admin's call, not
    // the party the window polices (§11) — a field error, in the envelope.
    $response = $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/preferences', ['validation_window_days' => 30])
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed');
    expect($response->json('error.meta.fields.validation_window_days'))->not->toBeNull();
    expect($merchant->refresh()->validation_window_days)->toBe(2);
});

// ------------------------------------------------------ product categories

it('creates and edits product categories, slug frozen through renames', function () {
    $merchant = settingsRatedMerchantMr5();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    $categoryId = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/product-categories', [
            'name_en' => 'Hot Drinks',
            'name_dv' => 'ހޫނު ބުއިން',
            'mode' => 'rate',
            'cashback_rate_percent' => '3.00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'hot-drinks')
        ->assertJsonPath('data.mode', 'rate')
        ->assertJsonPath('data.cashback_rate_percent', '3.00')
        ->assertJsonPath('data.active', true)
        ->json('data.id');

    // A rename keeps the slug — it is the public line key vendors integrate
    // against, and transaction_lines snapshot it.
    $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/product-categories/{$categoryId}", [
            'name_en' => 'Cold Drinks',
        ])
        ->assertOk()
        ->assertJsonPath('data.name_en', 'Cold Drinks')
        ->assertJsonPath('data.slug', 'hot-drinks');

    // Flipping to excluded drops the rate — the mode/rate pair must cohere.
    $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/product-categories/{$categoryId}", [
            'mode' => 'excluded',
            'cashback_rate_percent' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'excluded')
        ->assertJsonPath('data.cashback_rate_percent', null);
});

// -------------------------------------------------------------- promotions

it('runs the promotion lifecycle: draft, publish, immutable, cancel-draft-only', function () {
    $merchant = settingsRatedMerchantMr5();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    $payload = [
        'cashback_rate_percent' => '5.00',
        'starts_at' => now()->addDay()->toIso8601String(),
        'ends_at' => now()->addDays(8)->toIso8601String(),
        'min_purchase_laari' => 100000,
    ];

    // The draft carries the §4 all-in cost preview, tier-cliff data included
    // — what the app's create sheet warns with.
    $promotionId = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/promotions', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.cashback_rate_percent', '5.00')
        ->assertJsonPath('cost_preview.standing.cashback_rate_percent', '2.00')
        ->assertJsonPath('cost_preview.tier_changed', true)
        ->json('data.id');

    $this->withHeaders($headers)
        ->postJson("/api/mobile/v1/merchant/promotions/{$promotionId}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // Published means immutable for the stated duration (§7): a second
    // publish and a cancel both answer 409, enveloped.
    $republish = $this->withHeaders($headers)
        ->postJson("/api/mobile/v1/merchant/promotions/{$promotionId}/publish")
        ->assertStatus(409);
    expect($republish->json('error.code'))->toBe('conflict');

    $cancelPublished = $this->withHeaders($headers)
        ->postJson("/api/mobile/v1/merchant/promotions/{$promotionId}/cancel")
        ->assertStatus(409);
    expect($cancelPublished->json('error.code'))->toBe('conflict');

    // A DRAFT cancels cleanly — the one lifecycle exit that stays open.
    $draftId = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/promotions', [
            ...$payload,
            'cashback_rate_percent' => '4.00',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withHeaders($headers)
        ->postJson("/api/mobile/v1/merchant/promotions/{$draftId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // The list the screen renders, newest first.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/promotions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'cancelled')
        ->assertJsonPath('data.1.status', 'published');
});

// -------------------------------------------------------------------- rate

it('changes the standing rate with the §7 scheduling semantics', function () {
    $merchant = settingsRatedMerchantMr5();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    // An INCREASE applies immediately — a till quoting the old rate can
    // only under-promise.
    $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/rate', ['cashback_rate_percent' => '3.00'])
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', '3.00')
        ->assertJsonPath('data.pending', null)
        ->assertJsonPath('change.applies', 'immediately')
        ->assertJsonPath('change.previous.cashback_rate_percent', '2.00');

    // A DECREASE waits for the next business midnight — the advertised rate
    // is honoured until then, so a stale till can never over-promise.
    $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/rate', ['cashback_rate_percent' => '2.50'])
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', '3.00')
        ->assertJsonPath('data.pending.cashback_rate_percent', '2.50')
        ->assertJsonPath('change.applies', 'next_business_midnight');

    // The MR2 GET reads the same window — the app's Cashback Settings
    // screen shows the pending decrease it just scheduled.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/rate')
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', '3.00')
        ->assertJsonPath('data.pending.cashback_rate_percent', '2.50');
});

// ------------------------------------------------- the two gate strengths

it('freezes the commercial offer of a suspended store but not its estate', function () {
    $merchant = settingsRatedMerchantMr5();
    $merchant->update(['status' => 'suspended']);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = settingsHeadersMr5($owner);

    // `:trading` (§7): a suspended store creates no cashback, so a rate or
    // promotion set now would only spring live on reinstatement.
    $rate = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/rate', ['cashback_rate_percent' => '3.00'])
        ->assertStatus(409);
    expect($rate->json('error.code'))->toBe('store_not_trading');

    $promo = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/promotions', [
            'cashback_rate_percent' => '5.00',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDays(8)->toIso8601String(),
        ])
        ->assertStatus(409);
    expect($promo->json('error.code'))->toBe('store_not_trading');

    // But the DEFAULT gate admits it: suspension is credit control, never a
    // locked panel — the store still fixes its profile while it settles up.
    $this->withHeaders($headers)
        ->patchJson('/api/mobile/v1/merchant/profile', ['name' => 'Still My Shop'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Still My Shop');
});
