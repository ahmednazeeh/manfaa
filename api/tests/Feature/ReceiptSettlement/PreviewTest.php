<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Money\Laari;
use App\Domain\Platform\BankAccountService;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Models\AdminUser;
use App\Models\Settlement;
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
    $this->actingAs($this->fixture->user, 'merchant');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('previews the exact amount the eventual settlement will owe', function () {
    app(BankAccountService::class)->create([
        'bank_name' => 'Bank of Maldives',
        'account_no' => '7730000123456',
        'account_name' => 'Manfaa Pvt Ltd',
        'is_primary' => true,
        'active' => true,
    ]);

    $preview = $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 4)
        ->assertJsonPath('data.line_total_laari', 11_825)
        ->assertJsonPath('data.credit_applied_laari', 0)
        ->assertJsonPath('data.amount_due_laari', 11_825)
        ->assertJsonPath('data.amount_due_mvr', '118.25')
        // Where to send it, and what to quote — before any commitment.
        ->assertJsonPath('data.payment_instructions.bank_account.bank_name', 'Bank of Maldives')
        ->assertJsonPath('data.payment_instructions.bank_account.account_no', '7730000123456')
        ->assertJsonPath('data.payment_instructions.needs_configuration', false)
        ->assertJsonPath('data.payment_instructions.reference_preview', 'ST-2026-00001')
        // Documented: the FINAL reference is assigned at submit.
        ->assertJsonPath('data.payment_instructions.reference_is_final', false)
        ->json('data');

    // Nothing was claimed or reserved by previewing.
    expect(Settlement::query()->count())->toBe(0);

    // Preview twice: still no draft, still the same answer.
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.amount_due_laari', 11_825)
        ->assertJsonPath('data.payment_instructions.reference_preview', 'ST-2026-00001');

    // Now submit for real: the settlement matches the preview to the laari.
    $created = $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => $preview['amount_due_laari'],
        'bank_ref' => 'BML-PREVIEW-1',
        'slip' => Slips::jpeg(),
    ])->assertCreated()->json('data');

    expect($created['amount_due_laari'])->toBe($preview['amount_due_laari'])
        ->and($created['cashback_total_laari'])->toBe($preview['cashback_total_laari'])
        ->and($created['fee_total_laari'])->toBe($preview['fee_total_laari'])
        ->and($created['fee_gst_total_laari'])->toBe($preview['fee_gst_total_laari'])
        ->and($created['sale_total_laari'])->toBe($preview['sale_total_laari'])
        ->and($created['due_at'])->toBe($preview['due_at'])
        ->and(count($created['lines']))->toBe($preview['transaction_count'])
        // The preview's reference guess was right here, but the contract only
        // promises the FINAL reference on the created batch.
        ->and($created['reference'])->toBe($preview['payment_instructions']['reference_preview']);
});

it('previews an explicit selection, and refuses one that is not eligible', function () {
    $ids = array_slice($this->fixture->transactionIds(), 0, 2);

    $this->getJson('/api/merchant/settlements/preview?transaction_ids[]='.$ids[0].'&transaction_ids[]='.$ids[1])
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 2)
        ->assertJsonPath('data.amount_due_laari', 2_750 + 1_375)
        ->assertJsonPath('data.transaction_ids', $ids);

    // Claim one of them on a real batch, then preview it again.
    $this->post('/api/merchant/settlements', [
        'transaction_ids' => [$ids[0]],
        'amount' => 2_750,
        'bank_ref' => 'BML-PREVIEW-2',
        'slip' => Slips::png(),
    ])->assertCreated();

    $this->getJson('/api/merchant/settlements/preview?transaction_ids[]='.$ids[0])
        ->assertUnprocessable();

    // And the settle-all preview now covers only what is left.
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 3)
        ->assertJsonPath('data.amount_due_laari', 11_825 - 2_750);
});

it('nets §7 credit adjustments into the preview exactly as the batch will', function () {
    $builder = app(SettlementBuilder::class);
    $allocator = app(SettlementAllocator::class);
    $admin = AdminUser::factory()->create();

    // Settle + confirm the oldest line for real, then refund it: §6 routes
    // the correction to a pending credit adjustment (already_confirmed).
    $t0 = $this->fixture->transactions[0];
    $own = $builder->submit($builder->createDraft($this->fixture->merchant, [$t0->id]));
    $payment = $allocator->recordBankPayment($own, Laari::of(2_750), 'BML-PREVIEW-NET');
    $allocator->matchPayment($payment, $admin);

    app(ReversalService::class)->reverse($t0, Actor::system(), 'customer_refund', now()->toImmutable());

    // The remaining three lines total 9,075; the 2,750 credit nets in.
    $this->actingAs($this->fixture->user, 'merchant');

    $preview = $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.line_total_laari', 9_075)
        ->assertJsonPath('data.credit_applied_laari', 2_750)
        ->assertJsonPath('data.amount_due_laari', 9_075 - 2_750)
        ->json('data');

    $created = $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => $preview['amount_due_laari'],
        'bank_ref' => 'BML-PREVIEW-NET-2',
        'slip' => Slips::jpeg(),
    ])->assertCreated()->json('data');

    expect($created['amount_due_laari'])->toBe($preview['amount_due_laari'])
        ->and($created['amount_due_laari'])->toBe(6_325);
});

it('says so plainly when no platform bank account is configured', function () {
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.payment_instructions.bank_account', null)
        ->assertJsonPath('data.payment_instructions.needs_configuration', true);
});

it('refuses to preview when there is nothing to settle', function () {
    $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => 11_825,
        'bank_ref' => 'BML-PREVIEW-3',
        'slip' => Slips::jpeg(),
    ])->assertCreated();

    $this->getJson('/api/merchant/settlements/preview?settle_all=1')->assertUnprocessable();
});

it('refuses a receipt for a batch fully covered by credits, and settles it from the wallet route instead', function () {
    $builder = app(SettlementBuilder::class);
    $allocator = app(SettlementAllocator::class);
    $admin = AdminUser::factory()->create();

    // Confirm + refund the 2,750 line, then keep ONLY the 1,375 and 2,200
    // lines out of the way so the next batch is exactly the 2,750-equivalent.
    $t0 = $this->fixture->transactions[0];
    $own = $builder->submit($builder->createDraft($this->fixture->merchant, [$t0->id]));
    $payment = $allocator->recordBankPayment($own, Laari::of(2_750), 'BML-ZERO-1');
    $allocator->matchPayment($payment, $admin);
    app(ReversalService::class)->reverse($t0, Actor::system(), 'customer_refund', now()->toImmutable());

    // A batch over the 2,750-due line only: the credit nets it to zero.
    $target = $this->fixture->transactions[2]; // due 5,500 — too big
    $small = $this->fixture->transactions[1];  // due 1,375

    $this->actingAs($this->fixture->user, 'merchant');

    // 1,375 due against a 2,750 credit: FIFO refuses to over-apply, so this
    // batch still owes 1,375 and takes a normal receipt.
    $this->getJson('/api/merchant/settlements/preview?transaction_ids[]='.$small->id)
        ->assertOk()
        ->assertJsonPath('data.credit_applied_laari', 0)
        ->assertJsonPath('data.amount_due_laari', 1_375);

    // 5,500 due against the 2,750 credit → 2,750 owed.
    $this->getJson('/api/merchant/settlements/preview?transaction_ids[]='.$target->id)
        ->assertOk()
        ->assertJsonPath('data.credit_applied_laari', 2_750)
        ->assertJsonPath('data.amount_due_laari', 2_750);
});
