<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Crediting posts to the ledger, so the accounts have to exist.
    $this->seed(LedgerAccountSeeder::class);
});

/** A merchant that can actually price a sale: active, with a standing rate. */
function creditableMerchant(int $validationWindowDays = 15): Merchant
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

/**
 * PLAN-mobile-api.md M5 — write reliability for the till: idempotent
 * credits so a draining offline queue cannot double-book a sale, and a
 * clock guard so a drifted tablet cannot silently create irreversible debt.
 */
function till(Merchant $merchant): array
{
    $user = MerchantUser::factory()->for($merchant)->owner()->create();
    $token = app(MobileTokenService::class)->issue($user, MobileAudience::Merchant, 'Till')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

function creditBody(Customer $customer, string $invoice = 'INV-1'): array
{
    return [
        'customer_code' => $customer->customer_code,
        'invoice_no' => $invoice,
        'eligible_amount' => 100000,
    ];
}

// ------------------------------------------------------- idempotency

it('records a credit from the till app', function () {
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'k-1'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer))
        ->assertCreated();

    expect(Transaction::query()->count())->toBe(1);
});

it('replays the original response instead of booking a second sale', function () {
    // The offline-queue case: the till sent a sale, the reply was lost, and
    // the queue retries with the SAME key. Booking it twice would credit the
    // customer twice and bill the merchant twice.
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();
    $headers = till($merchant) + ['Idempotency-Key' => 'k-retry'];

    $first = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer))
        ->assertCreated();

    app('auth')->forgetGuards();

    $second = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer));

    expect($second->headers->get('Idempotency-Replay'))->toBe('true');
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(Transaction::query()->count())->toBe(1);
});

it('refuses a credit with no idempotency key', function () {
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();

    $response = $this->withHeaders(till($merchant))
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer))
        ->assertStatus(422);

    // Enveloped by the mobile tree (M2), even though the refusal comes from
    // middleware returning a response rather than throwing.
    expect($response->json('error.code'))->toBe('idempotency_key_required');
    expect(Transaction::query()->count())->toBe(0);
});

it('refuses the same key carrying a different sale', function () {
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();
    $headers = till($merchant) + ['Idempotency-Key' => 'k-mismatch'];

    $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer, 'INV-A'))
        ->assertCreated();

    app('auth')->forgetGuards();

    // A reused key with different contents is a client bug, not a retry —
    // answering the FIRST sale's response would be a lie about the second.
    $response = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer, 'INV-B'))
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('idempotency_key_reuse_mismatch');
    expect(Transaction::query()->count())->toBe(1);
});

it('scopes idempotency keys to the store, not to the staff account', function () {
    // Two tills in one shop retrying the same key are retrying the SAME
    // sale; they must collide rather than book it twice.
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'shared'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer))
        ->assertCreated();

    app('auth')->forgetGuards();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'shared'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer))
        ->assertOk();

    expect(Transaction::query()->count())->toBe(1);
});

// -------------------------------------------------------- the clock guard

it('refuses a backdated sale that nobody acknowledged', function () {
    // A tablet whose clock has drifted — or reset after a flat battery —
    // would otherwise stamp every queued sale far enough back to skip the
    // refund window, creating a shift's worth of irreversible debt.
    $merchant = creditableMerchant(2);
    $customer = Customer::factory()->create();

    $response = $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'k-old'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer) + [
            'occurred_at' => now()->subDays(30)->format('Y-m-d H:i:s'),
        ])
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('backdated_confirmation_required');
    // The server's own time travels with the refusal, so the app can tell
    // the operator their clock is wrong rather than just failing.
    expect($response->json('error.meta.server_time'))->toBeString();
    expect(Transaction::query()->count())->toBe(0);
});

it('records a backdated sale when the app confirms it means to', function () {
    $merchant = creditableMerchant(2);
    $customer = Customer::factory()->create();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'k-old-ok'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer) + [
            'occurred_at' => now()->subDays(30)->format('Y-m-d H:i:s'),
            'backdated_acknowledged' => true,
        ])
        ->assertCreated();

    $transaction = Transaction::query()->firstOrFail();

    // Genuinely backdated: it skipped the refund window entirely.
    expect((bool) $transaction->backdated)->toBeTrue();
});

it('leaves an ordinary same-day sale alone', function () {
    $merchant = creditableMerchant(2);
    $customer = Customer::factory()->create();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'k-today'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer) + [
            'occurred_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
        ])
        ->assertCreated();

    expect((bool) Transaction::query()->firstOrFail()->backdated)->toBeFalse();
});

it('still refuses a future-dated sale', function () {
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'k-future'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer) + [
            'occurred_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])
        ->assertStatus(422);

    expect(Transaction::query()->count())->toBe(0);
});

// ---------------------------------------------------------- permissions

it('refuses a staff account that may not credit', function () {
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();

    $role = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Viewer',
        'slug' => 'viewer-'.$merchant->id,
        'permissions' => [Permission::SettlementsView->value],
        'is_owner' => false,
        'is_system' => false,
    ]);
    $user = MerchantUser::factory()->for($merchant)->withRole($role)->create();
    $token = app(MobileTokenService::class)->issue($user, MobileAudience::Merchant, 'Till')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'k-denied',
    ])->postJson('/api/mobile/v1/merchant/credits', creditBody($customer))
        ->assertStatus(403);

    expect($response->json('error.code'))->toBe('permission_required');
    // The slug survives the envelope, so the app can say WHICH permission.
    expect($response->json('error.meta.permission'))->toBe(Permission::CreditsCreate->value);
});

it('refuses a customer token outright', function () {
    $customer = Customer::factory()->create();
    $token = app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Customer, 'Phone')->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'k-wrong-audience',
    ])->postJson('/api/mobile/v1/merchant/credits', creditBody($customer))
        ->assertStatus(401);

    expect(Transaction::query()->count())->toBe(0);
});

// -------------------------------------------------- the invoice backstop

it('still refuses a duplicate invoice under a different key', function () {
    // The idempotency key covers retries; (merchant_id, invoice_no) covers
    // everything else, and remains the final authority.
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'k-1'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer, 'INV-DUP'))
        ->assertCreated();

    app('auth')->forgetGuards();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'k-2'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer, 'INV-DUP'))
        ->assertStatus(409);

    expect(Transaction::query()->count())->toBe(1);
});

it('credits the customer and leaves the money rules to the domain', function () {
    $merchant = creditableMerchant();
    $customer = Customer::factory()->create();

    $this->withHeaders(till($merchant) + ['Idempotency-Key' => 'k-money'])
        ->postJson('/api/mobile/v1/merchant/credits', creditBody($customer))
        ->assertCreated();

    $transaction = Transaction::query()->firstOrFail();

    expect($transaction->customer_id)->toBe($customer->getKey());
    expect($transaction->eligible_laari)->toBe(100000);
    // Whatever the rate resolves to, the row carries a positive cashback and
    // is in a live state — the arithmetic itself is the domain's own tested
    // territory, not this endpoint's.
    expect($transaction->cashback_laari)->toBeGreaterThan(0);
    expect($transaction->state)->not->toBe(TransactionState::Reversed->value);
});
