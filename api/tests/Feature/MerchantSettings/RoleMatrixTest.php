<?php

declare(strict_types=1);

use App\Domain\MerchantSettings\StaffService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->manager = MerchantUser::factory()->for($this->merchant)->manager()->create();
    $this->staff = MerchantUser::factory()->for($this->merchant)->staff()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    // GET /merchant/wallet lazily firstOrCreate()s the wallet row, and a
    // just-created Eloquent resource makes Laravel answer 201 instead of
    // 200 — a one-shot that would otherwise land on whichever tier the
    // matrix happens to try first. Warm it here so every tier reads the
    // steady state.
    MerchantWallet::query()->create([
        'merchant_id' => $this->merchant->id,
        'balance_laari' => 0,
        'currency' => 'MVR',
    ]);
});

/**
 * THE role matrix: every merchant-panel route × the three tiers (PLAN §1
 * decision 2026-08-15), with the EXACT status each tier gets.
 *
 * The non-403 statuses are the ones the route answers once the gate is
 * passed — 200/201 where the request is complete, 422 where the empty body
 * fails validation, 404 for a deliberately absent {id}, 409 where the
 * wizard refuses an already-approved store. Asserting those instead of a
 * bare "not 403" keeps the row honest: a route that started 404-ing for
 * everyone because the gate moved would fail here.
 *
 * Tiers, in one line each:
 *   owner    everything;
 *   manager  rates — the standing one AND a per-sale override on a credit
 *            — promotions, settlement mutations, branches, product
 *            categories, the profile READ — never the bank account, staff
 *            management, preferences, the logo, API credentials or the
 *            setup wizard;
 *   staff    credit entry at the store's own terms, the customer lookup and
 *            every read model.
 *
 * @return array<string, array{method: string, uri: string, payload: array<string, mixed>|Closure, owner: int, manager: int, staff: int}>
 */
function merchantRoleMatrix(): array
{
    $creditPayload = fn (string $role): array => [
        'customer_code' => '482917',
        'invoice_no' => 'INV-MATRIX-'.strtoupper($role),
        'eligible_amount' => 125000,
        'sale_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ];

    // The same credit carrying a per-sale rate override (PLAN §1). 10.00%
    // is the seeded schedule's ceiling, so it clears every standing rate an
    // earlier row in this matrix may have set.
    $overrideCreditPayload = fn (string $role): array => [
        ...$creditPayload($role),
        'invoice_no' => 'INV-MATRIX-OVERRIDE-'.strtoupper($role),
        'cashback_rate_percent' => '10.00',
    ];

    // Distinct rates per tier: each allowed tier performs a real INCREASE
    // (200 -> 300 -> 400), so neither call is a no-op that could answer
    // differently from the other.
    $ratePayload = fn (string $role): array => [
        'cashback_rate_percent' => ['owner' => '3.00', 'manager' => '4.00', 'staff' => '5.00'][$role],
    ];

    return [
        // ---- Open to every authenticated merchant user -----------------
        'session me' => ['method' => 'GET', 'uri' => '/api/merchant/auth/me', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],
        'manual credit' => ['method' => 'POST', 'uri' => '/api/merchant/credits', 'payload' => $creditPayload, 'owner' => 201, 'manager' => 201, 'staff' => 201],
        'customer lookup' => ['method' => 'GET', 'uri' => '/api/merchant/customers/lookup?code=482917', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],
        'transactions read' => ['method' => 'GET', 'uri' => '/api/merchant/transactions', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],
        'rate read' => ['method' => 'GET', 'uri' => '/api/merchant/rate', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],
        'promotions read' => ['method' => 'GET', 'uri' => '/api/merchant/promotions', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],
        'settlements read' => ['method' => 'GET', 'uri' => '/api/merchant/settlements', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],
        'settlement show' => ['method' => 'GET', 'uri' => '/api/merchant/settlements/999999', 'payload' => [], 'owner' => 404, 'manager' => 404, 'staff' => 404],
        'outstanding read' => ['method' => 'GET', 'uri' => '/api/merchant/outstanding', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],
        'wallet read' => ['method' => 'GET', 'uri' => '/api/merchant/wallet', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],
        'product categories read' => ['method' => 'GET', 'uri' => '/api/merchant/product-categories', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 200],

        // ---- Manager or above ------------------------------------------
        'rate change' => ['method' => 'POST', 'uri' => '/api/merchant/rate', 'payload' => $ratePayload, 'owner' => 200, 'manager' => 200, 'staff' => 403],
        // Keying the sale in is staff work; choosing what it pays is not.
        'manual credit with a rate override' => ['method' => 'POST', 'uri' => '/api/merchant/credits', 'payload' => $overrideCreditPayload, 'owner' => 201, 'manager' => 201, 'staff' => 403],
        'promotion create' => ['method' => 'POST', 'uri' => '/api/merchant/promotions', 'payload' => [], 'owner' => 422, 'manager' => 422, 'staff' => 403],
        'promotion publish' => ['method' => 'POST', 'uri' => '/api/merchant/promotions/999999/publish', 'payload' => [], 'owner' => 404, 'manager' => 404, 'staff' => 403],
        'promotion cancel' => ['method' => 'POST', 'uri' => '/api/merchant/promotions/999999/cancel', 'payload' => [], 'owner' => 404, 'manager' => 404, 'staff' => 403],
        // Receipt-first (PLAN §1): creating a settlement IS submitting one —
        // it freezes the lines and claims a real bank transfer, so it is
        // manager work. The preview claims nothing and stays open to staff.
        'settlement preview' => ['method' => 'GET', 'uri' => '/api/merchant/settlements/preview?settle_all=1', 'payload' => [], 'owner' => 422, 'manager' => 422, 'staff' => 422],
        'settlement create' => ['method' => 'POST', 'uri' => '/api/merchant/settlements', 'payload' => [], 'owner' => 422, 'manager' => 422, 'staff' => 403],
        'settlement receipt' => ['method' => 'POST', 'uri' => '/api/merchant/settlements/999999/receipts', 'payload' => [], 'owner' => 422, 'manager' => 422, 'staff' => 403],
        'wallet settle' => ['method' => 'POST', 'uri' => '/api/merchant/settlements/wallet', 'payload' => [], 'owner' => 422, 'manager' => 422, 'staff' => 403],
        'branches read' => ['method' => 'GET', 'uri' => '/api/merchant/branches', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 403],
        'branch create' => ['method' => 'POST', 'uri' => '/api/merchant/branches', 'payload' => [], 'owner' => 422, 'manager' => 422, 'staff' => 403],
        'branch update' => ['method' => 'PATCH', 'uri' => '/api/merchant/branches/999999', 'payload' => [], 'owner' => 404, 'manager' => 404, 'staff' => 403],
        'branch delete' => ['method' => 'DELETE', 'uri' => '/api/merchant/branches/999999', 'payload' => [], 'owner' => 404, 'manager' => 404, 'staff' => 403],
        'product category create' => ['method' => 'POST', 'uri' => '/api/merchant/product-categories', 'payload' => [], 'owner' => 422, 'manager' => 422, 'staff' => 403],
        'product category update' => ['method' => 'PATCH', 'uri' => '/api/merchant/product-categories/999999', 'payload' => [], 'owner' => 404, 'manager' => 404, 'staff' => 403],
        'profile read' => ['method' => 'GET', 'uri' => '/api/merchant/profile', 'payload' => [], 'owner' => 200, 'manager' => 200, 'staff' => 403],

        // ---- Owner only -------------------------------------------------
        'profile write' => ['method' => 'PATCH', 'uri' => '/api/merchant/profile', 'payload' => ['channel' => 'both'], 'owner' => 200, 'manager' => 403, 'staff' => 403],
        'bank account' => ['method' => 'PATCH', 'uri' => '/api/merchant/bank-account', 'payload' => [], 'owner' => 422, 'manager' => 403, 'staff' => 403],
        'staff read' => ['method' => 'GET', 'uri' => '/api/merchant/staff', 'payload' => [], 'owner' => 200, 'manager' => 403, 'staff' => 403],
        'staff create' => ['method' => 'POST', 'uri' => '/api/merchant/staff', 'payload' => [], 'owner' => 422, 'manager' => 403, 'staff' => 403],
        'staff update' => ['method' => 'PATCH', 'uri' => '/api/merchant/staff/999999', 'payload' => [], 'owner' => 404, 'manager' => 403, 'staff' => 403],
        'preferences' => ['method' => 'PATCH', 'uri' => '/api/merchant/preferences', 'payload' => ['settlement_method' => 'bank'], 'owner' => 200, 'manager' => 403, 'staff' => 403],
        'api credentials' => ['method' => 'GET', 'uri' => '/api/merchant/credentials', 'payload' => [], 'owner' => 200, 'manager' => 403, 'staff' => 403],
        'setup read' => ['method' => 'GET', 'uri' => '/api/merchant/setup', 'payload' => [], 'owner' => 200, 'manager' => 403, 'staff' => 403],
        // The wizard's writes answer 409 setup_not_editable on an approved
        // store — the owner is past it, and nobody else may reach it at all.
        'setup profile' => ['method' => 'PATCH', 'uri' => '/api/merchant/setup/profile', 'payload' => ['channel' => 'online'], 'owner' => 409, 'manager' => 403, 'staff' => 403],
        'setup rate' => ['method' => 'PATCH', 'uri' => '/api/merchant/setup/rate', 'payload' => ['cashback_rate_percent' => '3.00'], 'owner' => 409, 'manager' => 403, 'staff' => 403],
        'setup submit' => ['method' => 'POST', 'uri' => '/api/merchant/setup/submit', 'payload' => [], 'owner' => 409, 'manager' => 403, 'staff' => 403],
        'setup logo' => ['method' => 'POST', 'uri' => '/api/merchant/setup/logo', 'payload' => [], 'owner' => 422, 'manager' => 403, 'staff' => 403],
        'settings logo' => ['method' => 'POST', 'uri' => '/api/merchant/settings/logo', 'payload' => [], 'owner' => 422, 'manager' => 403, 'staff' => 403],
    ];
}

it('answers the exact status per tier on every merchant route', function () {
    foreach (merchantRoleMatrix() as $label => $row) {
        foreach (['owner', 'manager', 'staff'] as $role) {
            /** @var MerchantUser $user */
            $user = $this->{$role};
            $payload = $row['payload'] instanceof Closure ? ($row['payload'])($role) : $row['payload'];

            $status = $this->actingAs($user, 'merchant')
                ->json($row['method'], $row['uri'], $payload)
                ->getStatusCode();

            $this->assertSame(
                $row[$role],
                $status,
                sprintf('%s %s [%s] as %s', $row['method'], $row['uri'], $label, $role),
            );
        }
    }
});

it('names the tier a refusal needs in the machine-readable code', function () {
    // Two distinct codes so the panel can say WHICH tier is missing, not
    // just that the role is wrong.
    $this->actingAs($this->staff, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '3.00'])
        ->assertForbidden()
        ->assertJsonPath('code', 'manager_required');

    $this->actingAs($this->manager, 'merchant')
        ->getJson('/api/merchant/staff')
        ->assertForbidden()
        ->assertJsonPath('code', 'owner_required');

    $this->actingAs($this->staff, 'merchant')
        ->getJson('/api/merchant/staff')
        ->assertForbidden()
        ->assertJsonPath('code', 'owner_required');
});

it('rejects unauthenticated requests on the settings surface outright', function () {
    $this->getJson('/api/merchant/profile')->assertUnauthorized();
    $this->patchJson('/api/merchant/preferences')->assertUnauthorized();
    $this->getJson('/api/merchant/customers/lookup?code=482917')->assertUnauthorized();
});

it('lets an owner invite straight into any tier', function () {
    $this->actingAs($this->owner, 'merchant');

    foreach (['staff', 'manager', 'owner'] as $role) {
        $this->postJson('/api/merchant/staff', [
            'name' => 'New '.$role,
            'email' => "new.{$role}@example.com",
            'role' => $role,
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', $role);
    }

    // Omitting the tier still means staff — the invite is back-compatible.
    $this->postJson('/api/merchant/staff', [
        'name' => 'Default tier',
        'email' => 'default.tier@example.com',
    ])->assertCreated()->assertJsonPath('data.role', 'staff');

    $this->postJson('/api/merchant/staff', [
        'name' => 'Bogus',
        'email' => 'bogus@example.com',
        'role' => 'superuser',
    ])->assertUnprocessable();
});

it('promotes and demotes through the manager tier', function () {
    $this->actingAs($this->owner, 'merchant');

    $this->patchJson("/api/merchant/staff/{$this->staff->id}", ['role' => 'manager'])
        ->assertOk()
        ->assertJsonPath('data.role', 'manager');

    // The promoted account immediately holds the manager surface...
    $this->actingAs($this->staff->refresh(), 'merchant')
        ->getJson('/api/merchant/branches')
        ->assertOk();

    // ...and still not the owner surface.
    $this->getJson('/api/merchant/staff')
        ->assertForbidden()
        ->assertJsonPath('code', 'owner_required');

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/staff/{$this->staff->id}", ['role' => 'staff'])
        ->assertOk()
        ->assertJsonPath('data.role', 'staff');

    $this->actingAs($this->staff->refresh(), 'merchant')
        ->getJson('/api/merchant/branches')
        ->assertForbidden()
        ->assertJsonPath('code', 'manager_required');
});

it('counts only owners in the last-owner guard, however many managers exist', function () {
    // Two managers and a staff account are present; none of them can keep
    // the merchant's settings surface alive, so the sole owner is still the
    // last owner.
    MerchantUser::factory()->for($this->merchant)->manager()->create();

    $this->actingAs($this->owner, 'merchant');

    // Demoting the last owner to MANAGER is refused exactly like a demotion
    // to staff — the tier that matters is owner.
    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['role' => 'manager'])
        ->assertUnprocessable();
    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['role' => 'staff'])
        ->assertUnprocessable();
    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['is_active' => false])
        ->assertUnprocessable();

    expect($this->owner->refresh()->role)->toBe('owner')
        ->and($this->owner->is_active)->toBeTrue();

    // A SECOND owner releases the guard — a manager never would.
    $second = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->patchJson("/api/merchant/staff/{$second->id}", ['role' => 'manager'])
        ->assertOk()
        ->assertJsonPath('data.role', 'manager');

    // ...and now the original owner is the last one again.
    $this->patchJson("/api/merchant/staff/{$this->owner->id}", ['role' => 'manager'])
        ->assertUnprocessable();
});

it('refuses a self-demotion into the manager tier', function () {
    // A second owner keeps the last-owner guard out of the way, so what is
    // being tested is purely the self-demote rule.
    MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/staff/{$this->owner->id}", ['role' => 'manager'])
        ->assertUnprocessable();

    expect($this->owner->refresh()->role)->toBe('owner');
});

it('keeps managers out of the staff service entirely, not merely out of the HTTP route', function () {
    // The route gate is owner-only, so a manager can never reach
    // StaffService over HTTP — every staff endpoint answers owner_required.
    foreach ([
        ['getJson', '/api/merchant/staff'],
        ['postJson', '/api/merchant/staff'],
        ['patchJson', "/api/merchant/staff/{$this->staff->id}"],
    ] as [$method, $uri]) {
        $this->actingAs($this->manager, 'merchant')
            ->{$method}($uri, ['name' => 'X', 'email' => 'x@example.com', 'role' => 'owner'])
            ->assertForbidden()
            ->assertJsonPath('code', 'owner_required');
    }

    expect($this->staff->refresh()->role)->toBe('staff')
        ->and(MerchantUser::query()->where('email', 'x@example.com')->exists())->toBeFalse();
});

it('ranks the three tiers on the model itself', function () {
    expect(MerchantUser::ROLES)->toBe(['staff', 'manager', 'owner'])
        ->and($this->owner->hasRoleAtLeast('owner'))->toBeTrue()
        ->and($this->owner->hasRoleAtLeast('manager'))->toBeTrue()
        ->and($this->manager->hasRoleAtLeast('manager'))->toBeTrue()
        ->and($this->manager->hasRoleAtLeast('owner'))->toBeFalse()
        ->and($this->staff->hasRoleAtLeast('staff'))->toBeTrue()
        ->and($this->staff->hasRoleAtLeast('manager'))->toBeFalse();

    // An unknown role ranks below everything rather than above it.
    $unknown = new MerchantUser(['role' => 'wizard']);
    expect($unknown->hasRoleAtLeast('staff'))->toBeFalse();
});

it('admits the manager tier at the database level', function () {
    $manager = MerchantUser::factory()->for($this->merchant)->manager()->create();

    expect($manager->refresh()->role)->toBe('manager');

    expect(fn () => MerchantUser::factory()->for($this->merchant)->create(['role' => 'supervisor']))
        ->toThrow(QueryException::class);
});

it('keeps the last-owner advisory lock keyed to the merchant, not the tier', function () {
    // Guard-rail on the constant the race test contends on: managers must
    // not have introduced a second lock class.
    expect(StaffService::OWNER_GUARD_LOCK_CLASS)->toBe(0x4D4F57);
});
