<?php

declare(strict_types=1);

use App\Domain\Money\Laari;
use App\Domain\Settlement\WalletFunding;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    $this->fixture = SettlementFixture::payableBatch();
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('walks the full merchant + admin settlement flow over HTTP', function () {
    $this->actingAs($this->fixture->user, 'merchant');

    // Outstanding before settling: the whole §4 batch sits in the 0–5 bucket.
    $this->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 4)
        ->assertJsonPath('data.total.payable_laari', 11825)
        ->assertJsonPath('data.total.payable_mvr', '118.25')
        ->assertJsonPath('data.buckets.0_5.count', 4)
        ->assertJsonPath('data.buckets.overdue.count', 0);

    // Receipt-first (PLAN §1): submitting the slip IS the creation, and it
    // lands directly in payment_review — awaiting_payment never happens on
    // the merchant path.
    $settlementId = $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => 11780,
        'bank_ref' => 'BML-20260805-01',
        'slip' => Slips::jpeg(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->assertJsonPath('data.reference', 'ST-2026-00001')
        ->assertJsonPath('data.amount_due_laari', 11825)
        ->assertJsonPath('data.amount_due_mvr', '118.25')
        ->assertJsonPath('data.merchant_status.code', 'verifying')
        ->assertJsonPath('data.merchant_status.message', 'Manfaa is verifying your transfer.')
        ->assertJsonCount(4, 'data.lines')
        ->json('data.id');

    // Detail with lines, from the merchant's own listing.
    $this->getJson("/api/merchant/settlements/{$settlementId}")
        ->assertOk()
        ->assertJsonCount(4, 'data.lines')
        ->assertJsonPath('data.lines.0.due_laari', 2750)
        ->assertJsonPath('data.payments.0.state', 'pending')
        ->assertJsonPath('data.payments.0.has_slip', true);

    $this->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Admin queue: the batch shows up filtered by state.
    $this->actingAs($this->admin, 'admin');

    $this->getJson('/api/admin/settlements?state=payment_review')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $settlementId);

    $this->getJson('/api/admin/settlements?state=settled')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $paymentId = $this->getJson("/api/admin/settlements/{$settlementId}")
        ->assertOk()
        ->json('data.payments.0.id');

    // 11780 against 11825: the 45-laari shortfall is forgiven — settled.
    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.amount_received_laari', 11780);

    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertConflict();

    $this->getJson("/api/admin/settlements/{$settlementId}")
        ->assertOk()
        ->assertJsonPath('data.lines.0.transaction.state', 'confirmed');
});

it('creates a settlement from explicit transaction ids', function () {
    $this->actingAs($this->fixture->user, 'merchant');
    $ids = array_slice($this->fixture->transactionIds(), 0, 2);

    $this->post('/api/merchant/settlements', [
        'transaction_ids' => $ids,
        'amount' => 2750 + 1375,
        'bank_ref' => 'BML-IDS-1',
        'slip' => Slips::png(),
    ])
        ->assertCreated()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.amount_due_laari', 2750 + 1375);

    // A transaction already frozen on a live batch cannot be settled twice.
    $this->post('/api/merchant/settlements', [
        'transaction_ids' => [$ids[0]],
        'amount' => 2750,
        'bank_ref' => 'BML-IDS-2',
        'slip' => Slips::png(),
    ])->assertUnprocessable();

    // Neither ids nor settle_all is a validation error.
    $this->postJson('/api/merchant/settlements', [])
        ->assertUnprocessable();
});

it('settles from the wallet over HTTP and shows the movements', function () {
    app(WalletFunding::class)->recordTopUp($this->fixture->merchant, Laari::of(20000), 'BML-TOPUP-9');

    $this->actingAs($this->fixture->user, 'merchant');

    $this->postJson('/api/merchant/settlements/wallet', ['settle_all' => true])
        ->assertCreated()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.funding_method', 'wallet');

    $this->getJson('/api/merchant/wallet')
        ->assertOk()
        ->assertJsonPath('data.balance_laari', 20000 - 11825)
        ->assertJsonPath('data.balance_mvr', '81.75')
        ->assertJsonCount(2, 'data.transactions');
});

it('rejects wallet settlement the balance cannot cover', function () {
    $this->actingAs($this->fixture->user, 'merchant');

    $this->postJson('/api/merchant/settlements/wallet', ['settle_all' => true])
        ->assertUnprocessable();

    // Nothing was created: a failed wallet settlement leaves no batch behind
    // and the transactions stay eligible.
    $this->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson('/api/merchant/outstanding')
        ->assertJsonPath('data.total.count', 4);
});

it('never leaks another merchant\'s settlement', function () {
    $this->actingAs($this->fixture->user, 'merchant');

    $settlementId = $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => 11825,
        'bank_ref' => 'BML-LEAK-1',
        'slip' => Slips::jpeg(),
    ])->assertCreated()->json('data.id');

    $otherMerchant = Merchant::factory()->create();
    $intruder = MerchantUser::factory()->for($otherMerchant)->owner()->create();

    $this->actingAs($intruder, 'merchant');

    // Merchant B sees a plain 404 on merchant A's batch.
    $this->getJson("/api/merchant/settlements/{$settlementId}")->assertNotFound();

    // And B's own listing stays empty.
    $this->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('requires the right guard on every route', function () {
    $this->getJson('/api/merchant/settlements')->assertUnauthorized();
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')->assertUnauthorized();
    $this->getJson('/api/merchant/outstanding')->assertUnauthorized();
    $this->getJson('/api/merchant/wallet')->assertUnauthorized();
    $this->getJson('/api/admin/settlements')->assertUnauthorized();

    // A merchant token does not open admin routes.
    $this->actingAs($this->fixture->user, 'merchant');
    $this->getJson('/api/admin/settlements')->assertUnauthorized();
    $this->getJson('/api/admin/settlements/1/slip')->assertUnauthorized();
});

it('keeps the admin fallback: admin-built batch, recorded payment, match', function () {
    $this->actingAs($this->admin, 'admin');

    $merchantId = $this->fixture->merchant->id;

    $settlementId = $this->postJson("/api/admin/merchants/{$merchantId}/settlements", ['settle_all' => true])
        ->assertCreated()
        ->assertJsonPath('data.state', 'awaiting_payment')
        ->assertJsonPath('data.amount_due_laari', 11825)
        ->json('data.id');

    $paymentId = $this->postJson("/api/admin/settlements/{$settlementId}/payments", [
        'amount' => 11825,
        'bank_ref' => 'BML-FALLBACK-1',
        'slip_path' => 'reconciled-from-statement',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending')
        ->json('data.id');

    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled');
});
