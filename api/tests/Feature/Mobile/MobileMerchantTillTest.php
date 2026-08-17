<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Merchant app MR2 — the remaining TILL endpoints on mobile/v1: the customer
 * name-confirmation, the till void (amend/cancel), the live rate estimate and
 * the category list behind the split editor. All mounted from the SAME web
 * controllers behind auth:sanctum + mobile.token:merchant; refusals leave in
 * the one mobile envelope.
 */
beforeEach(function () {
    // Crediting and reversing post to the ledger, so the accounts must exist.
    $this->seed(LedgerAccountSeeder::class);
});

/** An active store that can price a sale: standing rate, no minimum. */
function tillMerchantMr2(int $validationWindowDays = 15): Merchant
{
    $merchant = Merchant::factory()->create([
        'validation_window_days' => $validationWindowDays,
        'min_eligible_laari' => 0,
    ]);

    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    return $merchant;
}

/** Bearer headers for a mobile merchant token issued to $user. */
function tillHeadersMr2(MerchantUser $user): array
{
    $token = app(MobileTokenService::class)
        ->issue($user, MobileAudience::Merchant, 'Till')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/** Books a sale through the mobile till and returns the transaction id. */
function tillCreditMr2(array $headers, Customer $customer, int $eligible = 118000): int
{
    return test()->withHeaders($headers + ['Idempotency-Key' => 'k-'.fake()->unique()->uuid()])
        ->postJson('/api/mobile/v1/merchant/credits', [
            'customer_code' => $customer->customer_code,
            'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 99999),
            'eligible_amount' => $eligible,
        ])
        ->assertCreated()
        ->json('data.id');
}

// ------------------------------------------------------------- the lookup

it('resolves a known active customer to their full name for staff', function () {
    $merchant = tillMerchantMr2();
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();

    Customer::factory()->create([
        'customer_code' => '482917',
        'name' => 'Aisha Mohamed',
        'status' => 'active',
    ]);

    // The exact web payload — the controller is the web's, unchanged.
    $this->withHeaders(tillHeadersMr2($staff))
        ->getJson('/api/mobile/v1/merchant/customers/lookup?code=482917')
        ->assertOk()
        ->assertExactJson([
            'valid' => true,
            'name' => 'Aisha Mohamed',
        ]);
});

it('answers a plain valid:false for an unknown code', function () {
    $merchant = tillMerchantMr2();
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();

    $this->withHeaders(tillHeadersMr2($staff))
        ->getJson('/api/mobile/v1/merchant/customers/lookup?code=999999')
        ->assertOk()
        ->assertExactJson(['valid' => false]);
});

it('refuses the lookup to a role that may not look customers up', function () {
    $merchant = tillMerchantMr2();
    $role = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Viewer',
        'slug' => 'viewer-'.$merchant->id,
        'permissions' => [Permission::SettlementsView->value],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $user = MerchantUser::factory()->for($merchant)->withRole($role)->create();

    $response = $this->withHeaders(tillHeadersMr2($user))
        ->getJson('/api/mobile/v1/merchant/customers/lookup?code=482917')
        ->assertStatus(403);

    // Enveloped, and naming WHICH permission is missing.
    expect($response->json('error.code'))->toBe('permission_required');
    expect($response->json('error.meta.permission'))->toBe(Permission::CustomersLookup->value);
});

it('throttles the mobile lookup at 30/min in a bucket the web does not share', function () {
    $merchant = tillMerchantMr2();
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();
    $headers = tillHeadersMr2($staff);

    Customer::factory()->create(['customer_code' => '482917', 'status' => 'active']);

    // A VALID code on purpose: the per-merchant daily miss budget is shared
    // with the web by design and must not be what trips here.
    foreach (range(1, 30) as $i) {
        $this->withHeaders($headers)
            ->getJson('/api/mobile/v1/merchant/customers/lookup?code=482917')
            ->assertOk();
    }

    $refused = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/customers/lookup?code=482917')
        ->assertStatus(429);

    // The refusal is enveloped, with the standard backing-off signal.
    expect($refused->json('error.code'))->toBe('rate_limited');
    expect($refused->headers->has('Retry-After'))->toBeTrue();

    app('auth')->forgetGuards();

    // The SAME user's WEB session is untouched — the mobile bucket is its
    // own, so a till burning its minute cannot blind the browser panel
    // (and vice versa).
    $this->actingAs($staff, 'merchant')
        ->getJson('/api/merchant/customers/lookup?code=482917')
        ->assertOk()
        ->assertJsonPath('valid', true);
});

it('keys the lookup limiter on the model as well as the id', function () {
    // ThrottleRequests sorts ABOVE EnsureMobileToken, so a customer token's
    // rejected requests reach the limiter. With the web's inline
    // `throttle:30,1` form the key would be sha1(id) with no model
    // discriminator — customer #42 could lock out merchant-user #42 at an
    // unrelated store. The named limiter discriminates on class.
    $merchant = Merchant::factory()->create();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $customer = (new Customer)->forceFill(['id' => $owner->getKey()]);

    $limiter = RateLimiter::limiter('mobile-lookup');

    expect($limiter)->not->toBeNull();

    $limitFor = function ($user) use ($limiter) {
        $request = Request::create('/api/mobile/v1/merchant/customers/lookup', 'GET');
        $request->setUserResolver(fn () => $user);

        return $limiter($request);
    };

    expect($limitFor($owner)->key)->not->toBe($limitFor($customer)->key);
    expect($limitFor($owner)->key)->toContain(MerchantUser::class);
    // The web's own number: 30 a minute per user.
    expect($limitFor($owner)->maxAttempts)->toBe(30);
});

// ----------------------------------------------------------- the till void

it('amends a mistyped total from the till', function () {
    $merchant = tillMerchantMr2();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = tillHeadersMr2($owner);
    $customer = Customer::factory()->create();

    $id = tillCreditMr2($headers, $customer);

    $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertOk()
        ->assertJsonPath('data.eligible_laari', 11800)
        // ceil(11,800 × 200 ÷ 10,000) = 236 — repriced in full.
        ->assertJsonPath('data.cashback_laari', 236)
        // The terms are untouched: an amendment fixes the amount, never the
        // rate the sale was rung up under.
        ->assertJsonPath('data.cashback_rate_percent', '2.00');

    expect(Transaction::query()->findOrFail($id)->eligible_laari)->toBe(11800);
});

it('envelopes an amend validation refusal (422)', function () {
    $merchant = tillMerchantMr2();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = tillHeadersMr2($owner);
    $customer = Customer::factory()->create();

    $id = tillCreditMr2($headers, $customer);

    // A reference-only full total below the eligible amount describes no
    // real receipt.
    $response = $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/transactions/{$id}", [
            'eligible_amount' => 100000,
            'sale_amount' => 50000,
        ])
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('sale_below_eligible');
    expect(Transaction::query()->findOrFail($id)->eligible_laari)->toBe(118000);
});

it('refuses to amend once the validation window has closed, in the envelope', function () {
    $merchant = tillMerchantMr2();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = tillHeadersMr2($owner);
    $customer = Customer::factory()->create();

    $id = tillCreditMr2($headers, $customer);

    // Past the window the money is inside the §7 clock — the state machine
    // has moved on and the correction is the platform's to make.
    Transaction::query()->findOrFail($id)->forceFill(['state' => 'payable_unfunded'])->save();

    $response = $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertStatus(409);

    expect($response->json('error.code'))->toBe('not_amendable_state');
    expect(Transaction::query()->findOrFail($id)->eligible_laari)->toBe(118000);
});

it('cancels a sale outright with a reason', function () {
    $merchant = tillMerchantMr2();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = tillHeadersMr2($owner);
    $customer = Customer::factory()->create();

    $id = tillCreditMr2($headers, $customer);

    $this->withHeaders($headers)
        ->postJson("/api/mobile/v1/merchant/transactions/{$id}/cancel", ['reason' => 'refund'])
        ->assertOk()
        ->assertJsonPath('data.state', 'reversed');
});

it('never cancels or amends a backdated credit — it skipped the window and is final', function () {
    $merchant = tillMerchantMr2();
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    $headers = tillHeadersMr2($owner);
    $customer = Customer::factory()->create();

    $id = tillCreditMr2($headers, $customer);
    Transaction::query()->whereKey($id)->update(['backdated' => true]);

    $cancel = $this->withHeaders($headers)
        ->postJson("/api/mobile/v1/merchant/transactions/{$id}/cancel", ['reason' => 'error'])
        ->assertStatus(409);

    expect($cancel->json('error.code'))->toBe('backdated_irreversible');

    $amend = $this->withHeaders($headers)
        ->patchJson("/api/mobile/v1/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertStatus(409);

    expect($amend->json('error.code'))->toBe('backdated_irreversible');

    $transaction = Transaction::query()->findOrFail($id);
    expect($transaction->state)->not->toBe('reversed');
    expect($transaction->eligible_laari)->toBe(118000);
});

// ------------------------------------------------------------------ rate

it('serves the live rate estimate — current plus pending window — to staff', function () {
    $merchant = tillMerchantMr2();
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();

    // A §7 decrease already scheduled for the next business midnight.
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 150,
        'effective_from' => now()->addDay()->startOfDay(),
        'effective_to' => null,
    ]);

    $response = $this->withHeaders(tillHeadersMr2($staff))
        ->getJson('/api/mobile/v1/merchant/rate')
        ->assertOk();

    // The web panel's exact shape (RateResource): 2-decimal percent strings,
    // never basis points, with the §4 fee and all-in cost alongside.
    expect($response->json('data.current.cashback_rate_percent'))->toBe('2.00');
    expect($response->json('data.current.platform_fee_percent'))->toBe('0.75');
    expect($response->json('data.current.all_in_percent'))->toBe('2.75');
    expect($response->json('data.current.effective_from'))->toBeString();
    expect($response->json('data.pending.cashback_rate_percent'))->toBe('1.50');
});

it('serves a null pending window when no change is scheduled', function () {
    $merchant = tillMerchantMr2();
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();

    $response = $this->withHeaders(tillHeadersMr2($staff))
        ->getJson('/api/mobile/v1/merchant/rate')
        ->assertOk();

    expect($response->json('data.current.cashback_rate_percent'))->toBe('2.00');
    expect($response->json('data.pending'))->toBeNull();
});

// ------------------------------------------------------------- categories

it('serves the category list to staff — the split editor feed', function () {
    $merchant = tillMerchantMr2();
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();

    MerchantProductCategory::query()->create([
        'merchant_id' => $merchant->id, 'slug' => 'fruits', 'name_en' => 'Fruits',
        'name_dv' => 'މޭވާ', 'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);
    MerchantProductCategory::query()->create([
        'merchant_id' => $merchant->id, 'slug' => 'electronics', 'name_en' => 'Electronics',
        'name_dv' => 'އިލެކްޓްރޯނިކްސް', 'mode' => 'rate', 'rate_bp' => 500, 'active' => true, 'sort' => 2,
    ]);

    $response = $this->withHeaders(tillHeadersMr2($staff))
        ->getJson('/api/mobile/v1/merchant/product-categories')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($response->json('data.0.slug'))->toBe('fruits');
    expect($response->json('data.0.mode'))->toBe('excluded');
    expect($response->json('data.0.cashback_rate_percent'))->toBeNull();
    expect($response->json('data.1.slug'))->toBe('electronics');
    expect($response->json('data.1.cashback_rate_percent'))->toBe('5.00');
});

it('gates the category list exactly as the web does', function () {
    $merchant = tillMerchantMr2();
    $role = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Viewer',
        'slug' => 'viewer-cat-'.$merchant->id,
        'permissions' => [Permission::SettlementsView->value],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $user = MerchantUser::factory()->for($merchant)->withRole($role)->create();

    $response = $this->withHeaders(tillHeadersMr2($user))
        ->getJson('/api/mobile/v1/merchant/product-categories')
        ->assertStatus(403);

    expect($response->json('error.code'))->toBe('permission_required');
    expect($response->json('error.meta.permission'))->toBe(Permission::ProductCategoriesView->value);
});

// ------------------------------------------------------------------ authz

it('refuses a customer token on every till endpoint', function () {
    $customer = Customer::factory()->create();
    $token = app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Customer, 'Phone')->plainTextToken;

    $calls = [
        ['getJson', '/api/mobile/v1/merchant/customers/lookup?code=482917'],
        ['patchJson', '/api/mobile/v1/merchant/transactions/1'],
        ['postJson', '/api/mobile/v1/merchant/transactions/1/cancel'],
        ['getJson', '/api/mobile/v1/merchant/rate'],
        ['getJson', '/api/mobile/v1/merchant/product-categories'],
    ];

    foreach ($calls as [$method, $uri]) {
        app('auth')->forgetGuards();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->{$method}($uri)
            ->assertStatus(401);

        expect($response->json('error.code'))->toBe('unauthenticated');
    }
});

it('answers an unauthenticated lookup with the enveloped 401, not a 404', function () {
    // The live smoke's local twin: the route exists before credentials do.
    $response = $this->getJson('/api/mobile/v1/merchant/customers/lookup')
        ->assertStatus(401);

    expect($response->json('error.code'))->toBe('unauthenticated');
});
