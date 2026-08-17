<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Money\Laari;
use App\Domain\Settlement\WalletFunding;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use App\Models\Settlement;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Merchant app MR3 — the settlements suite on mobile/v1: outstanding, the
 * wallet pair, list/detail, the preview, and the three money writes
 * (receipt-first submit, wallet settle, further receipt). All mounted from the
 * SAME web controller (routes/api/settlements.php) behind auth:sanctum +
 * mobile.token:merchant, with the web's permission slugs first and the
 * DEFAULT EnsureMerchantApproved on the writes — suspended stores settle,
 * pre-approval stores do not. Refusals leave in the one mobile envelope.
 */
beforeEach(function () {
    // Settling posts to the ledger, so the accounts must exist; slips land on
    // their own private disk.
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
});

afterEach(function () {
    // SettlementFixture pins the clock at BASE + 4 days.
    Carbon::setTestNow();
});

/** Bearer headers for a mobile merchant token issued to $user. */
function settlementsHeadersMr3(MerchantUser $user): array
{
    $token = app(MobileTokenService::class)
        ->issue($user, MobileAudience::Merchant, 'Phone')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Every route of the suite with the permission slug that guards it — the
 * gating loop and both refusal loops walk this one table so a ninth route
 * cannot be mounted without a test noticing the map changed.
 *
 * @return list<array{string, string, string}> [method, uri, permission]
 */
function settlementsRoutesMr3(): array
{
    return [
        ['getJson', '/api/mobile/v1/merchant/outstanding', Permission::SettlementsView->value],
        ['getJson', '/api/mobile/v1/merchant/wallet', Permission::WalletView->value],
        ['getJson', '/api/mobile/v1/merchant/settlements', Permission::SettlementsView->value],
        ['getJson', '/api/mobile/v1/merchant/settlements/preview?settle_all=1', Permission::SettlementsPreview->value],
        ['getJson', '/api/mobile/v1/merchant/settlements/1', Permission::SettlementsView->value],
        ['postJson', '/api/mobile/v1/merchant/settlements', Permission::SettlementsCreate->value],
        ['postJson', '/api/mobile/v1/merchant/settlements/wallet', Permission::WalletSettle->value],
        ['postJson', '/api/mobile/v1/merchant/settlements/1/receipts', Permission::SettlementsReceiptAdd->value],
    ];
}

// ------------------------------------------------------------------- authz

it('answers unauthenticated with the enveloped 401 on every settlement route', function () {
    // The live smoke's local twin: the routes exist before credentials do,
    // and the refusal is the envelope, never a redirect or a 404.
    foreach (settlementsRoutesMr3() as [$method, $uri]) {
        $response = $this->{$method}($uri)->assertStatus(401);

        expect($response->json('error.code'))->toBe('unauthenticated');
    }
});

it('refuses a customer token on every settlement route', function () {
    $customer = Customer::factory()->create();
    $token = app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Customer, 'Phone')->plainTextToken;

    foreach (settlementsRoutesMr3() as [$method, $uri]) {
        app('auth')->forgetGuards();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->{$method}($uri)
            ->assertStatus(401);

        expect($response->json('error.code'))->toBe('unauthenticated');
    }
});

it('gates every route on its own slug, before the store status can answer', function () {
    // A DRAFT store on purpose: were EnsureMerchantApproved ahead of the
    // permission check on the writes, these would answer 409
    // store_not_approved — telling an account that may not touch money how
    // the store stands. Permission first means 403 everywhere.
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
    $headers = settlementsHeadersMr3($user);

    foreach (settlementsRoutesMr3() as [$method, $uri, $permission]) {
        app('auth')->forgetGuards();

        $response = $this->withHeaders($headers)
            ->{$method}($uri)
            ->assertStatus(403);

        // Enveloped, and naming WHICH permission is missing.
        expect($response->json('error.code'))->toBe('permission_required');
        expect($response->json('error.meta.permission'))->toBe($permission);
    }
});

// ------------------------------------------------------------------- reads

it('serves the outstanding summary — the §4 batch in the 0–5 bucket', function () {
    $fixture = SettlementFixture::payableBatch();

    $this->withHeaders(settlementsHeadersMr3($fixture->user))
        ->getJson('/api/mobile/v1/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 4)
        ->assertJsonPath('data.total.payable_laari', 11825)
        ->assertJsonPath('data.total.payable_mvr', '118.25')
        ->assertJsonPath('data.buckets.0_5.count', 4)
        ->assertJsonPath('data.buckets.overdue.count', 0);
});

it('auto-creates the wallet row on first read', function () {
    $merchant = Merchant::factory()->create();
    // wallet.view seeds to the STAFF preset — the wallet card is a read.
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();

    expect(MerchantWallet::query()->where('merchant_id', $merchant->id)->exists())->toBeFalse();

    $headers = settlementsHeadersMr3($staff);

    // 201, not 200, on the very first read — firstOrCreate means the resource
    // rides out on a wasRecentlyCreated model, and Laravel's JsonResource
    // answers those with Created. The web mount does exactly the same; the
    // app treats both as success.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/wallet')
        ->assertCreated()
        ->assertJsonPath('data.balance_laari', 0)
        ->assertJsonPath('data.balance_mvr', '0.00')
        ->assertJsonPath('data.currency', 'MVR')
        ->assertJsonCount(0, 'data.transactions');

    expect(MerchantWallet::query()->where('merchant_id', $merchant->id)->exists())->toBeTrue();

    // Every read after that is a plain 200 on the same row.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/wallet')
        ->assertOk()
        ->assertJsonPath('data.balance_laari', 0);
});

it('previews settle-all and an explicit selection, claiming nothing', function () {
    $fixture = SettlementFixture::payableBatch();
    $headers = settlementsHeadersMr3($fixture->user);

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 4)
        ->assertJsonPath('data.amount_due_laari', 11825)
        ->assertJsonPath('data.amount_due_mvr', '118.25');

    $ids = array_slice($fixture->transactionIds(), 0, 2);

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/settlements/preview?transaction_ids[]='.$ids[0].'&transaction_ids[]='.$ids[1])
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 2)
        ->assertJsonPath('data.amount_due_laari', 2750 + 1375)
        ->assertJsonPath('data.transaction_ids', $ids);

    // Nothing was claimed or reserved by previewing.
    expect(Settlement::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ writes

it('creates a settlement from a multipart receipt upload, then lists and shows it', function () {
    $fixture = SettlementFixture::payableBatch();
    $headers = settlementsHeadersMr3($fixture->user);

    // Plain ->post(), not ->postJson(): the slip is a FILE, so this request
    // is multipart/form-data — the exact wire shape the Flutter app sends —
    // and it must survive the whole mobile stack (sanctum, mobile.token,
    // the permission gate, the approval gate, the envelope wrapper).
    $settlementId = $this->withHeaders($headers)
        ->post('/api/mobile/v1/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 11825,
            'bank_ref' => 'BML-MOBILE-1',
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->assertJsonPath('data.reference', 'ST-2026-00001')
        ->assertJsonPath('data.amount_due_laari', 11825)
        ->assertJsonPath('data.merchant_status.code', 'verifying')
        ->assertJsonCount(4, 'data.lines')
        ->assertJsonPath('data.payments.0.has_slip', true)
        ->json('data.id');

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/settlements')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $settlementId);

    $this->withHeaders($headers)
        ->getJson("/api/mobile/v1/merchant/settlements/{$settlementId}")
        ->assertOk()
        ->assertJsonCount(4, 'data.lines')
        ->assertJsonPath('data.lines.0.due_laari', 2750)
        ->assertJsonPath('data.payments.0.state', 'pending');
});

it('adds a further receipt to a batch still owed money', function () {
    $fixture = SettlementFixture::payableBatch();
    $headers = settlementsHeadersMr3($fixture->user);

    // A partial transfer first (§7): the batch is created owing more than
    // this payment claims to cover.
    $settlementId = $this->withHeaders($headers)
        ->post('/api/mobile/v1/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 5000,
            'bank_ref' => 'BML-PART-1',
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated()
        ->json('data.id');

    // The remainder, as its own receipt — multipart again.
    $this->withHeaders($headers)
        ->post("/api/mobile/v1/merchant/settlements/{$settlementId}/receipts", [
            'amount' => 6825,
            'bank_ref' => 'BML-PART-2',
            'slip' => Slips::pdf(),
        ])
        ->assertCreated()
        ->assertJsonCount(2, 'data.payments');
});

it('settles from the wallet and shows the movements', function () {
    $fixture = SettlementFixture::payableBatch();
    app(WalletFunding::class)->recordTopUp($fixture->merchant, Laari::of(20000), 'BML-TOPUP-M1');

    $headers = settlementsHeadersMr3($fixture->user);

    $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/settlements/wallet', ['settle_all' => true])
        ->assertCreated()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.funding_method', 'wallet');

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/wallet')
        ->assertOk()
        ->assertJsonPath('data.balance_laari', 20000 - 11825)
        ->assertJsonPath('data.balance_mvr', '81.75')
        ->assertJsonCount(2, 'data.transactions');
});

it('refuses a wallet settlement the balance cannot cover, in the envelope', function () {
    $fixture = SettlementFixture::payableBatch();
    $headers = settlementsHeadersMr3($fixture->user);

    $response = $this->withHeaders($headers)
        ->postJson('/api/mobile/v1/merchant/settlements/wallet', ['settle_all' => true])
        ->assertStatus(422);

    // abort(422, prose) → the envelope's validation_failed with the domain
    // sentence carried through as the fallback message, so an app that does
    // not know the refusal still says something true.
    expect($response->json('error.code'))->toBe('validation_failed');
    expect($response->json('error.message'))->toContain('wallet holds MVR 0.00');

    // Nothing was created: no batch, and the lines stay eligible.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/settlements')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/outstanding')
        ->assertJsonPath('data.total.count', 4);
});

// ---------------------------------------------------------- the status gate

it('lets a SUSPENDED store settle — the one act that ends the suspension', function () {
    // The default EnsureMerchantApproved (no :trading) refuses only the three
    // pre-approval statuses; suspended and closed are admitted by design
    // (PLAN §7): suspension is the platform's credit control, and a gate
    // refusing a suspended shopkeeper's money would make it permanent.
    $fixture = SettlementFixture::payableBatch();
    $fixture->merchant->update(['status' => 'suspended']);
    $headers = settlementsHeadersMr3($fixture->user);

    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.payable_laari', 11825);

    $this->withHeaders($headers)
        ->post('/api/mobile/v1/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 11825,
            'bank_ref' => 'BML-SUSP-1',
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review');
});

it('refuses the money writes to a store that never passed review', function () {
    $fixture = SettlementFixture::payableBatch();
    $fixture->merchant->update(['status' => 'pending_review']);
    $headers = settlementsHeadersMr3($fixture->user);

    // The other side of the same gate: submitting freezes lines and claims a
    // real transfer, so it belongs to a store that has been reviewed. 409
    // store_not_approved — the wizard conversation, enveloped.
    $response = $this->withHeaders($headers)
        ->post('/api/mobile/v1/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 11825,
            'bank_ref' => 'BML-PEND-1',
            'slip' => Slips::jpeg(),
        ])
        ->assertStatus(409);

    expect($response->json('error.code'))->toBe('store_not_approved');
    expect(Settlement::query()->count())->toBe(0);

    // Reads stay open in every status — the owner can still see what is owed.
    $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/merchant/outstanding')
        ->assertOk();
});
