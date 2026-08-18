<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\MerchantSettings\StaffService;
use App\Domain\Platform\PlatformConfig;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    // The marketplace kill switch wins over every permission by design: with
    // it off, its routes 404 for everyone, so a permission gate behind it
    // could never be the first answer. Switch it ON here so the matrix is
    // actually testing authority rather than the switch.
    app(PlatformConfig::class)->set('marketplace_enabled', 1);

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
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    // GET /merchant/wallet lazily firstOrCreate()s the wallet row, and a
    // just-created Eloquent resource makes Laravel answer 201 instead of
    // 200 — a one-shot that would otherwise land on whichever actor the
    // matrix happens to try first. Warm it here so every actor reads the
    // steady state.
    MerchantWallet::query()->create([
        'merchant_id' => $this->merchant->id,
        'balance_laari' => 0,
        'currency' => 'MVR',
    ]);
});

/**
 * An account of $merchant standing on a role that holds EXACTLY $slugs — no
 * preset, no flag, nothing inherited. Every row of the matrix is checked
 * through one of these, because a permission that only ever gets exercised
 * as part of a preset bundle is a permission nobody has tested.
 *
 * @param  list<string>  $slugs
 */
function merchantUserHolding(Merchant $merchant, array $slugs): MerchantUser
{
    $role = MerchantRole::query()->create([
        'merchant_id' => $merchant->getKey(),
        'name' => 'Matrix role',
        'slug' => 'matrix-'.Str::lower(Str::random(12)),
        'permissions' => $slugs,
        'is_owner' => false,
        'is_system' => false,
    ]);

    return MerchantUser::factory()->for($merchant)->withRole($role)->create();
}

/**
 * THE permission matrix: every gated merchant-panel route × the ONE
 * permission it names, with the EXACT status an actor holding it gets.
 *
 * This replaced a route × TIER matrix. A tier is an order, so the old table
 * could only say "manager and above"; a permission set has no order, and the
 * question each row now asks is sharper — does THIS route answer to THIS
 * slug and to nothing coarser? Which is why the refusal pass below hands the
 * actor the entire catalogue except the one slug: a route accidentally gated
 * on something broader would pass, and a row asserting only "staff gets 403"
 * never could catch it.
 *
 * The non-403 statuses are the ones the route answers once the gate is
 * passed — 200/201 where the request is complete, 422 where the empty body
 * fails validation, 404 for a deliberately absent {id}, 409 where the wizard
 * refuses an already-approved store. Asserting those instead of a bare "not
 * 403" keeps the row honest: a route that started 404-ing for everyone
 * because the gate moved would fail here.
 *
 * `also` is for the one check finer than a route — a FIELD on the credit
 * form — where reaching the refusal at all needs the route's own permission
 * first.
 *
 * @return array<string, array{method: string, uri: string, payload: array<string, mixed>|Closure, permission: string, allowed: int, also?: list<string>}>
 */
function merchantPermissionMatrix(): array
{
    $creditPayload = fn (string $actor): array => [
        'customer_code' => '482917',
        'invoice_no' => 'INV-MATRIX-'.strtoupper($actor),
        'eligible_amount' => 125000,
        'sale_amount' => 125000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ];

    // The same credit carrying a per-sale rate override (PLAN §1). 10.00% is
    // the seeded schedule's ceiling, so it clears every standing rate an
    // earlier row in this matrix may have set.
    $overrideCreditPayload = fn (string $actor): array => [
        ...$creditPayload($actor),
        'invoice_no' => 'INV-MATRIX-OVERRIDE-'.strtoupper($actor),
        'cashback_rate_percent' => '10.00',
    ];

    // Distinct rates per actor: each actor allowed through performs a real
    // INCREASE (200 -> 300 -> 400), so no call is a no-op that could answer
    // differently from the one before it.
    $ratePayload = fn (string $actor): array => [
        'cashback_rate_percent' => ['granted' => '3.00', 'owner' => '4.00', 'denied' => '5.00'][$actor],
    ];

    return [
        // ---- Till -------------------------------------------------------
        'manual credit' => ['method' => 'POST', 'uri' => '/api/merchant/credits', 'payload' => $creditPayload, 'permission' => 'credits.create', 'allowed' => 201],
        'customer lookup' => ['method' => 'GET', 'uri' => '/api/merchant/customers/lookup?code=482917', 'payload' => [], 'permission' => 'customers.lookup', 'allowed' => 200],
        'transactions read' => ['method' => 'GET', 'uri' => '/api/merchant/transactions', 'payload' => [], 'permission' => 'transactions.view', 'allowed' => 200],
        'transaction amend' => ['method' => 'PATCH', 'uri' => '/api/merchant/transactions/999999', 'payload' => [], 'permission' => 'transactions.amend', 'allowed' => 422],
        'transaction cancel' => ['method' => 'POST', 'uri' => '/api/merchant/transactions/999999/cancel', 'payload' => [], 'permission' => 'transactions.cancel', 'allowed' => 422],

        // ---- Money ------------------------------------------------------
        'settlements read' => ['method' => 'GET', 'uri' => '/api/merchant/settlements', 'payload' => [], 'permission' => 'settlements.view', 'allowed' => 200],
        'settlement show' => ['method' => 'GET', 'uri' => '/api/merchant/settlements/999999', 'payload' => [], 'permission' => 'settlements.view', 'allowed' => 404],
        // The unsettled-lines read model the settlement builder is assembled
        // from, not a surface of its own.
        'outstanding read' => ['method' => 'GET', 'uri' => '/api/merchant/outstanding', 'payload' => [], 'permission' => 'settlements.view', 'allowed' => 200],
        'settlement preview' => ['method' => 'GET', 'uri' => '/api/merchant/settlements/preview?settle_all=1', 'payload' => [], 'permission' => 'settlements.preview', 'allowed' => 422],
        // Receipt-first (PLAN §1): creating a settlement IS submitting one —
        // it freezes the lines and claims a real bank transfer. The preview
        // claims nothing, which is why the two are separate slugs.
        'settlement create' => ['method' => 'POST', 'uri' => '/api/merchant/settlements', 'payload' => [], 'permission' => 'settlements.create', 'allowed' => 422],
        'settlement receipt' => ['method' => 'POST', 'uri' => '/api/merchant/settlements/999999/receipts', 'payload' => [], 'permission' => 'settlements.receipt_add', 'allowed' => 422],
        'wallet read' => ['method' => 'GET', 'uri' => '/api/merchant/wallet', 'payload' => [], 'permission' => 'wallet.view', 'allowed' => 200],
        'wallet settle' => ['method' => 'POST', 'uri' => '/api/merchant/settlements/wallet', 'payload' => [], 'permission' => 'wallet.settle', 'allowed' => 422],

        // ---- Marketing --------------------------------------------------
        'promotions read' => ['method' => 'GET', 'uri' => '/api/merchant/promotions', 'payload' => [], 'permission' => 'promotions.view', 'allowed' => 200],
        'promotion create' => ['method' => 'POST', 'uri' => '/api/merchant/promotions', 'payload' => [], 'permission' => 'promotions.create', 'allowed' => 422],
        'promotion publish' => ['method' => 'POST', 'uri' => '/api/merchant/promotions/999999/publish', 'payload' => [], 'permission' => 'promotions.publish', 'allowed' => 404],
        'promotion cancel' => ['method' => 'POST', 'uri' => '/api/merchant/promotions/999999/cancel', 'payload' => [], 'permission' => 'promotions.cancel', 'allowed' => 404],

        // ---- Store ------------------------------------------------------
        'rate read' => ['method' => 'GET', 'uri' => '/api/merchant/rate', 'payload' => [], 'permission' => 'rate.view', 'allowed' => 200],
        'rate change' => ['method' => 'POST', 'uri' => '/api/merchant/rate', 'payload' => $ratePayload, 'permission' => 'rate.update', 'allowed' => 200],
        // Keying the sale in is till work; choosing what it pays is not, and
        // moving the store's published rate is a third thing again — hence a
        // slug of its own, checked on the FIELD behind credits.create.
        'manual credit with a rate override' => ['method' => 'POST', 'uri' => '/api/merchant/credits', 'payload' => $overrideCreditPayload, 'permission' => 'credits.custom_rate', 'also' => ['credits.create'], 'allowed' => 201],
        'product categories read' => ['method' => 'GET', 'uri' => '/api/merchant/product-categories', 'payload' => [], 'permission' => 'product_categories.view', 'allowed' => 200],
        'product category create' => ['method' => 'POST', 'uri' => '/api/merchant/product-categories', 'payload' => [], 'permission' => 'product_categories.create', 'allowed' => 422],
        'product category update' => ['method' => 'PATCH', 'uri' => '/api/merchant/product-categories/999999', 'payload' => [], 'permission' => 'product_categories.edit', 'allowed' => 404],
        'branches read' => ['method' => 'GET', 'uri' => '/api/merchant/branches', 'payload' => [], 'permission' => 'branches.view', 'allowed' => 200],
        'branch create' => ['method' => 'POST', 'uri' => '/api/merchant/branches', 'payload' => [], 'permission' => 'branches.create', 'allowed' => 422],
        'branch update' => ['method' => 'PATCH', 'uri' => '/api/merchant/branches/999999', 'payload' => [], 'permission' => 'branches.edit', 'allowed' => 404],
        'branch delete' => ['method' => 'DELETE', 'uri' => '/api/merchant/branches/999999', 'payload' => [], 'permission' => 'branches.delete', 'allowed' => 404],
        'profile read' => ['method' => 'GET', 'uri' => '/api/merchant/profile', 'payload' => [], 'permission' => 'profile.view', 'allowed' => 200],
        // 202: on a LIVE store the channel is a public claim, so a permitted
        // write queues for admin review instead of applying (MR9). The
        // permission is still the FIRST answer — 403 without it.
        'profile write' => ['method' => 'PATCH', 'uri' => '/api/merchant/profile', 'payload' => ['channel' => 'both'], 'permission' => 'profile.edit', 'allowed' => 202],
        // The store's own on/off switch. 200: an active store may pause
        // itself with no queue and no admin — but only with the authority.
        'publication' => ['method' => 'POST', 'uri' => '/api/merchant/publication', 'payload' => ['published' => true], 'permission' => 'store.publication', 'allowed' => 200],
        // Opening a shop on the marketplace: 200 for a permitted read of an
        // enrolment that does not exist yet (state `not_enrolled`), 403
        // without the authority.
        'marketplace enrolment' => ['method' => 'GET', 'uri' => '/api/merchant/marketplace/enrolment', 'payload' => [], 'permission' => 'marketplace.manage', 'allowed' => 200],
        'setup read' => ['method' => 'GET', 'uri' => '/api/merchant/setup', 'payload' => [], 'permission' => 'setup.view', 'allowed' => 200],
        // The wizard's writes answer 409 setup_not_editable on an approved
        // store — past the wizard, but past it for a reason that is not
        // authority, so the permission still has to be the first answer.
        'setup profile' => ['method' => 'PATCH', 'uri' => '/api/merchant/setup/profile', 'payload' => ['channel' => 'online'], 'permission' => 'setup.edit', 'allowed' => 409],
        'setup rate' => ['method' => 'PATCH', 'uri' => '/api/merchant/setup/rate', 'payload' => ['cashback_rate_percent' => '3.00'], 'permission' => 'setup.edit', 'allowed' => 409],
        'setup location' => ['method' => 'PATCH', 'uri' => '/api/merchant/setup/location', 'payload' => [], 'permission' => 'setup.edit', 'allowed' => 422],
        'setup submit' => ['method' => 'POST', 'uri' => '/api/merchant/setup/submit', 'payload' => [], 'permission' => 'setup.submit', 'allowed' => 409],
        // One handler, two URLs: which one the panel reached the logo through
        // is not an authority question, so both answer to branding.update.
        'setup logo' => ['method' => 'POST', 'uri' => '/api/merchant/setup/logo', 'payload' => [], 'permission' => 'branding.update', 'allowed' => 422],
        'settings logo' => ['method' => 'POST', 'uri' => '/api/merchant/settings/logo', 'payload' => [], 'permission' => 'branding.update', 'allowed' => 422],

        // ---- Account ----------------------------------------------------
        'bank account read' => ['method' => 'GET', 'uri' => '/api/merchant/bank-account', 'payload' => [], 'permission' => 'bank_account.view', 'allowed' => 200],
        'bank account write' => ['method' => 'PATCH', 'uri' => '/api/merchant/bank-account', 'payload' => [], 'permission' => 'bank_account.update', 'allowed' => 422],
        'preferences' => ['method' => 'PATCH', 'uri' => '/api/merchant/preferences', 'payload' => ['settlement_method' => 'bank'], 'permission' => 'preferences.update', 'allowed' => 200],
        'staff read' => ['method' => 'GET', 'uri' => '/api/merchant/staff', 'payload' => [], 'permission' => 'staff.view', 'allowed' => 200],
        'staff create' => ['method' => 'POST', 'uri' => '/api/merchant/staff', 'payload' => [], 'permission' => 'staff.invite', 'allowed' => 422],
        'staff update' => ['method' => 'PATCH', 'uri' => '/api/merchant/staff/999999', 'payload' => [], 'permission' => 'staff.edit', 'allowed' => 404],
        // MR8: the reset answers to staff.edit like the PATCH above — an
        // operation on an existing account, not the invite's mint.
        'staff password reset' => ['method' => 'POST', 'uri' => '/api/merchant/staff/999999/reset-password', 'payload' => [], 'permission' => 'staff.edit', 'allowed' => 404],
        'permission catalogue' => ['method' => 'GET', 'uri' => '/api/merchant/permissions', 'payload' => [], 'permission' => 'roles.view', 'allowed' => 200],
        'roles read' => ['method' => 'GET', 'uri' => '/api/merchant/roles', 'payload' => [], 'permission' => 'roles.view', 'allowed' => 200],
        'role create' => ['method' => 'POST', 'uri' => '/api/merchant/roles', 'payload' => [], 'permission' => 'roles.manage', 'allowed' => 422],
        'role update' => ['method' => 'PATCH', 'uri' => '/api/merchant/roles/999999', 'payload' => [], 'permission' => 'roles.manage', 'allowed' => 404],
        'role delete' => ['method' => 'DELETE', 'uri' => '/api/merchant/roles/999999', 'payload' => [], 'permission' => 'roles.manage', 'allowed' => 404],
        'api credentials read' => ['method' => 'GET', 'uri' => '/api/merchant/credentials', 'payload' => [], 'permission' => 'api_credentials.view', 'allowed' => 200],
        'api credential issue' => ['method' => 'POST', 'uri' => '/api/merchant/credentials', 'payload' => [], 'permission' => 'api_credentials.create', 'allowed' => 422],
        'api credential revoke' => ['method' => 'DELETE', 'uri' => '/api/merchant/credentials/999999', 'payload' => [], 'permission' => 'api_credentials.revoke', 'allowed' => 404],
    ];
}

/**
 * @param  array{payload: array<string, mixed>|Closure}  $row
 * @return array<string, mixed>
 */
function merchantMatrixPayload(array $row, string $actor): array
{
    return $row['payload'] instanceof Closure ? ($row['payload'])($actor) : $row['payload'];
}

it('answers the exact status to a role holding only the permission a route names', function () {
    foreach (merchantPermissionMatrix() as $label => $row) {
        $actor = merchantUserHolding($this->merchant, [$row['permission'], ...($row['also'] ?? [])]);

        $status = $this->actingAs($actor, 'merchant')
            ->json($row['method'], $row['uri'], merchantMatrixPayload($row, 'granted'))
            ->getStatusCode();

        $this->assertSame(
            $row['allowed'],
            $status,
            sprintf('%s %s [%s] holding only %s', $row['method'], $row['uri'], $label, $row['permission']),
        );
    }
});

it('refuses each route to a role holding the whole catalogue EXCEPT that route\'s own permission', function () {
    foreach (merchantPermissionMatrix() as $label => $row) {
        $actor = merchantUserHolding(
            $this->merchant,
            array_values(array_diff(Permission::values(), [$row['permission']])),
        );

        $response = $this->actingAs($actor, 'merchant')
            ->json($row['method'], $row['uri'], merchantMatrixPayload($row, 'denied'));

        $context = sprintf('%s %s [%s] without %s', $row['method'], $row['uri'], $label, $row['permission']);

        $this->assertSame(403, $response->getStatusCode(), $context);
        $this->assertSame('permission_required', $response->json('code'), $context);
        // The refusal names WHAT is missing, so the panel can say which
        // permission the user would need rather than "forbidden".
        $this->assertSame($row['permission'], $response->json('permission'), $context);
    }
});

it('gives an owner every route while the owner role stores no slug at all', function () {
    // §2.3: the owner's authority is the FLAG. An enumerated owner role
    // would be one deploy away from locking every store out of a new screen.
    expect($this->owner->role->permissions)->toBe([])
        ->and($this->owner->role->is_owner)->toBeTrue();

    foreach (merchantPermissionMatrix() as $label => $row) {
        $status = $this->actingAs($this->owner, 'merchant')
            ->json($row['method'], $row['uri'], merchantMatrixPayload($row, 'owner'))
            ->getStatusCode();

        $this->assertSame($row['allowed'], $status, sprintf('%s %s [%s] as owner', $row['method'], $row['uri'], $label));
    }
});

it('enforces every permission in the catalogue on some route', function () {
    // The catalogue is code, and a permission nothing checks is not a
    // permission. Adding a case to the enum without gating anything with it
    // ships a checkbox on the roles screen that grants nothing — this row is
    // what makes that a failing test rather than a support ticket.
    $enforced = array_values(array_unique(array_column(merchantPermissionMatrix(), 'permission')));

    expect($enforced)->toEqualCanonicalizing(Permission::values());
});

it('refuses everything to an account carrying no role', function () {
    // Nullable only so the column could be backfilled; null is never an
    // opening. This is also the shape a half-failed signup would leave.
    $roleless = MerchantUser::factory()->for($this->merchant)->create(['merchant_role_id' => null]);

    expect($roleless->resolvedPermissions())->toBe([]);

    foreach (merchantPermissionMatrix() as $label => $row) {
        $response = $this->actingAs($roleless, 'merchant')
            ->json($row['method'], $row['uri'], merchantMatrixPayload($row, 'denied'));

        $context = sprintf('%s %s [%s] with no role', $row['method'], $row['uri'], $label);

        $this->assertSame(403, $response->getStatusCode(), $context);
        $this->assertSame('permission_required', $response->json('code'), $context);
    }
});

it('refuses a slug outside the catalogue, to an owner as much as to anyone', function () {
    // Replaces "an unknown TIER ranks below everything". A tier could be
    // compared; a slug can only be recognised or not, and an unrecognised
    // one is a typo in the caller — so it is refused even by the account the
    // wildcard exists for, because denying everyone is how a typo gets
    // noticed instead of quietly skewing who may act.
    expect($this->owner->can('rate.destroy'))->toBeFalse()
        ->and($this->owner->can('rate:update'))->toBeFalse()
        ->and($this->owner->can(''))->toBeFalse()
        ->and($this->owner->can(['rate.update']))->toBeFalse()
        ->and($this->owner->can(Permission::RateUpdate))->toBeTrue();
});

it('throws on a gate naming a permission outside the catalogue instead of opening the route', function () {
    // The property the tier gate had and the permission gate must keep: a
    // misspelt gate is a 500 on the first request, never a route that
    // matches nothing and lets everyone through.
    Route::get('/permission-typo-test', fn () => response()->noContent())
        ->middleware(['auth:merchant', 'merchant.can:rate.destroy']);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->owner, 'merchant')->getJson('/permission-typo-test'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects unauthenticated requests on the settings surface outright', function () {
    $this->getJson('/api/merchant/profile')->assertUnauthorized();
    $this->patchJson('/api/merchant/preferences')->assertUnauthorized();
    $this->getJson('/api/merchant/customers/lookup?code=482917')->assertUnauthorized();
});

it('keeps the last-owner advisory lock keyed to the merchant', function () {
    // Guard-rail on the constant the race test contends on: the move to
    // roles must not have introduced a second lock class.
    expect(StaffService::OWNER_GUARD_LOCK_CLASS)->toBe(0x4D4F57);
});
