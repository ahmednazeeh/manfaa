<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\WalletFunding;
use App\Domain\Settlement\WalletTopUps;
use App\Http\Middleware\ThrottlePerRoute;
use App\Jobs\PollWalletTopUp;
use App\Jobs\ReadWalletTopUpReceipt;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PlatformBankAccount;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The merchant's wallet top-up CLAIM (owner, 2026-08-24): the same
 * receipt-first act a settlement uses — account, slip, optional reference —
 * creating a pending row that is not yet money.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');

    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->account = PlatformBankAccount::query()->create([
        'bank_name' => 'mib',
        'account_no' => '90501400021681001',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
    ]);
});

function topUpPayload(array $overrides = []): array
{
    return array_merge([
        'amount' => 20000,
        'platform_bank_account_id' => test()->account->id,
        'bank_ref' => 'BML-TOPUP-1',
        'slip' => Slips::jpeg(),
    ], $overrides);
}

it('creates a pending claim with the slip stored under the top-up, and shows it on the wallet', function (): void {
    $this->actingAs($this->owner, 'merchant')
        ->post('/api/merchant/wallet/top-ups', topUpPayload())
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.amount_laari', 20000)
        ->assertJsonPath('data.bank_ref', 'BML-TOPUP-1')
        ->assertJsonPath('data.has_slip', true)
        ->assertJsonPath('data.slip_mime', 'image/jpeg')
        ->assertJsonPath('data.platform_bank_account.bank_name', 'mib')
        ->assertJsonPath('data.auto_matched', false);

    $topUp = WalletTopUp::query()->sole();

    expect($topUp->uploaded_by)->toBe($this->owner->id)
        ->and($topUp->slip_path)->toStartWith("wallet-top-ups/{$this->merchant->id}/{$topUp->id}/")
        ->and($topUp->slip_path)->toEndWith('.jpg')
        ->and(Storage::disk('slips')->exists($topUp->slip_path))->toBeTrue()
        // The bank-watching window opened on the row.
        ->and($topUp->poll_started_at)->not->toBeNull()
        ->and($topUp->poll_until)->not->toBeNull();

    // Not money: nothing credited, no wallet row even needed yet.
    expect($this->merchant->wallet()->exists())->toBeFalse();

    // 201, not 200: the wallet row is lazily created by this first read.
    $this->getJson('/api/merchant/wallet')
        ->assertSuccessful()
        ->assertJsonPath('data.balance_laari', 0)
        ->assertJsonPath('data.top_up_min_laari', 10000)
        ->assertJsonPath('data.pending_top_ups.0.id', $topUp->id)
        ->assertJsonPath('data.pending_top_ups.0.amount_laari', 20000)
        ->assertJsonPath('data.pending_top_ups.0.state', 'pending')
        ->assertJsonPath('data.pending_top_ups.0.bank.bank_name', 'mib');
});

it('enforces the platform minimum, read live', function (): void {
    $this->actingAs($this->owner, 'merchant');

    // Under the shipped default (MVR 100).
    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['amount' => 9999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    // Raised by a superadmin: the form follows.
    app(PlatformConfig::class)->set('wallet_top_up_min_laari', 50000);

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['amount' => 20000]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    $this->getJson('/api/merchant/wallet')->assertJsonPath('data.top_up_min_laari', 50000);

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['amount' => 50000]))
        ->assertCreated();

    expect(WalletTopUp::query()->count())->toBe(1);
});

it('refuses a slip by its bytes, never its name', function (): void {
    // Six uploads in one test: past the route's 5/min throttle on purpose.
    $this->withoutMiddleware([ThrottleRequests::class, ThrottlePerRoute::class]);
    $this->actingAs($this->owner, 'merchant');

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['slip' => Slips::svg()]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'slip_unsupported_type');

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['slip' => Slips::spoofedPng()]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'slip_unsupported_type');

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['slip' => Slips::oversizeJpeg()]))
        ->assertUnprocessable();

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['slip' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slip');

    // Nothing created, nothing stored: a refused slip never makes a claim.
    expect(WalletTopUp::query()->count())->toBe(0)
        ->and(Storage::disk('slips')->allFiles())->toBe([]);

    // Every accepted format, extension from the signature.
    foreach (['png' => Slips::png(), 'webp' => Slips::webp(), 'pdf' => Slips::pdf()] as $extension => $slip) {
        $this->post('/api/merchant/wallet/top-ups', topUpPayload(['slip' => $slip, 'bank_ref' => 'REF-'.$extension]))
            ->assertCreated();

        expect(WalletTopUp::query()->where('bank_ref', 'REF-'.$extension)->sole()->slip_path)->toEndWith('.'.$extension);
    }
});

it('requires an ACTIVE platform account', function (): void {
    $this->actingAs($this->owner, 'merchant');

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['platform_bank_account_id' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platform_bank_account_id');

    $this->account->forceFill(['active' => false])->save();

    $this->post('/api/merchant/wallet/top-ups', topUpPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platform_bank_account_id');
});

it('refuses the same bank reference twice with a 409, and again once it is rejected', function (): void {
    $this->actingAs($this->owner, 'merchant');

    $this->post('/api/merchant/wallet/top-ups', topUpPayload())->assertCreated();

    // Double tap, retried request: the partial unique index catches it and
    // the loser rolls back whole — one row, one file.
    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['slip' => Slips::png()]))
        ->assertConflict()
        ->assertJsonPath('code', 'duplicate_bank_ref');

    expect(WalletTopUp::query()->count())->toBe(1)
        ->and(Storage::disk('slips')->allFiles())->toHaveCount(1);

    // A rejected claim releases its reference: the merchant can re-submit
    // the same transfer once the problem is sorted.
    WalletTopUp::query()->sole()->forceFill(['state' => 'rejected'])->save();

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['slip' => Slips::png()]))
        ->assertCreated();

    expect(WalletTopUp::query()->count())->toBe(2);
});

it('refuses a reference an admin already booked into the wallet', function (): void {
    // The admin top-up route recorded this transfer directly. Claiming it
    // again would only fail at match time; better at the form.
    app(WalletFunding::class)->recordTopUp($this->merchant, Laari::of(20000), 'BML-TOPUP-1');

    $this->actingAs($this->owner, 'merchant')
        ->post('/api/merchant/wallet/top-ups', topUpPayload())
        ->assertConflict()
        ->assertJsonPath('code', 'duplicate_bank_ref');

    expect(WalletTopUp::query()->count())->toBe(0);
});

it('does not need a reference at all', function (): void {
    $this->actingAs($this->owner, 'merchant')
        ->post('/api/merchant/wallet/top-ups', topUpPayload(['bank_ref' => null]))
        ->assertCreated()
        ->assertJsonPath('data.bank_ref', null);

    // Two unreferenced claims coexist: NULLs do not collide, and the admin
    // queue is what stands between them and a double credit.
    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['bank_ref' => '   ', 'slip' => Slips::png()]))
        ->assertCreated()
        ->assertJsonPath('data.bank_ref', null);
});

it('is gated on wallet.top_up, not on wallet.settle or wallet.view', function (): void {
    // Staff hold wallet.view; managers hold wallet.settle AND wallet.top_up.
    $staff = MerchantUser::factory()->for($this->merchant)->staff()->create();
    $manager = MerchantUser::factory()->for($this->merchant)->manager()->create();

    $this->actingAs($staff, 'merchant')
        ->post('/api/merchant/wallet/top-ups', topUpPayload())
        ->assertForbidden();

    $this->actingAs($manager, 'merchant')
        ->post('/api/merchant/wallet/top-ups', topUpPayload())
        ->assertCreated();
});

it('refuses a store that has not passed review', function (): void {
    $this->merchant->forceFill(['status' => 'pending_review'])->save();

    $this->actingAs($this->owner, 'merchant')
        ->post('/api/merchant/wallet/top-ups', topUpPayload())
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_approved');

    // A SUSPENDED store may still fund its wallet — settling is the one act
    // that ends a suspension. (The acting user caches its merchant across
    // requests in the harness; drop it so the gate reads the new status.)
    $this->merchant->forceFill(['status' => 'suspended'])->save();
    $this->owner->unsetRelation('merchant');

    $this->post('/api/merchant/wallet/top-ups', topUpPayload())->assertCreated();
});

it('starts watching the bank only when auto-verify is on', function (): void {
    Queue::fake();
    $this->actingAs($this->owner, 'merchant');

    $this->post('/api/merchant/wallet/top-ups', topUpPayload())->assertCreated();

    Queue::assertNotPushed(ReadWalletTopUpReceipt::class);
    Queue::assertNotPushed(PollWalletTopUp::class);

    TransferSetting::current()->forceFill([
        'auto_verify_enabled' => true,
        'verify_window_minutes' => 15,
    ])->save();

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['bank_ref' => 'BML-TOPUP-2', 'slip' => Slips::png()]))
        ->assertCreated();

    $topUp = WalletTopUp::query()->where('bank_ref', 'BML-TOPUP-2')->sole();

    expect($topUp->poll_until->diffInMinutes($topUp->poll_started_at, true))->toBe(15.0);

    Queue::assertPushed(ReadWalletTopUpReceipt::class, 1);
    Queue::assertPushed(PollWalletTopUp::class, 1);
});

it('is mounted on the mobile merchant surface with the same gate', function (): void {
    $token = app(MobileTokenService::class)
        ->issue($this->owner, MobileAudience::Merchant, 'Till')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->post('/api/mobile/v1/merchant/wallet/top-ups', topUpPayload())
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending');
});

it('lets only a superadmin move the top-up minimum', function (): void {
    $ordinary = AdminUser::factory()->create(['role' => 'admin']);

    $this->actingAs($ordinary, 'admin')
        ->patchJson('/api/admin/platform/settings/wallet_top_up_min_laari', ['value' => 50000])
        ->assertForbidden();

    $superadmin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($superadmin, 'admin')
        ->getJson('/api/admin/platform/settings')
        ->assertOk()
        ->assertJsonPath('data.wallet_top_up_min_laari.value', 10000)
        ->assertJsonPath('data.wallet_top_up_min_laari.min', 100);

    $this->patchJson('/api/admin/platform/settings/wallet_top_up_min_laari', ['value' => 50000])
        ->assertOk()
        ->assertJsonPath('data.wallet_top_up_min_laari.value', 50000);

    $this->patchJson('/api/admin/platform/settings/wallet_top_up_min_laari', ['value' => 99])
        ->assertUnprocessable();
});

it('refuses a reference the merchant already put on a settlement receipt, and the reverse', function (): void {
    $fixture = SettlementFixture::payableBatch();
    Carbon::setTestNow();

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->createDraft($fixture->merchant);
    $builder->submit($settlement);
    $settlement->refresh();

    $allocator = app(SettlementAllocator::class);
    $allocator->recordBankPayment($settlement, Laari::of((int) $settlement->amount_due_laari), 'ONE-TRANSFER');

    $owner = MerchantUser::factory()->for($fixture->merchant)->owner()->create();

    // Same transfer, now claimed as a top-up: refused at the form.
    $this->actingAs($owner, 'merchant')
        ->post('/api/merchant/wallet/top-ups', topUpPayload(['bank_ref' => 'ONE-TRANSFER']))
        ->assertConflict()
        ->assertJsonPath('code', 'duplicate_bank_ref');

    expect(WalletTopUp::query()->count())->toBe(0);

    // And a reference claimed as a top-up cannot then pay a batch.
    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['bank_ref' => 'OTHER-TRANSFER']))
        ->assertCreated();

    expect(fn () => $allocator->recordBankPayment($settlement->refresh(), Laari::of(100), 'OTHER-TRANSFER'))
        ->toThrow(DuplicateBankRefException::class);
});

it('caps the claims a store may have waiting at once', function (): void {
    $this->actingAs($this->owner, 'merchant');

    foreach (range(1, WalletTopUps::MAX_PENDING) as $n) {
        $this->post('/api/merchant/wallet/top-ups', topUpPayload(['bank_ref' => "REF-{$n}", 'slip' => Slips::png()]))
            ->assertCreated();
    }

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['bank_ref' => 'REF-MORE', 'slip' => Slips::png()]))
        ->assertConflict()
        ->assertJsonPath('code', 'too_many_pending_top_ups');

    expect(WalletTopUp::query()->count())->toBe(WalletTopUps::MAX_PENDING)
        ->and(Storage::disk('slips')->allFiles())->toHaveCount(WalletTopUps::MAX_PENDING);

    // A decided claim frees a slot.
    WalletTopUp::query()->orderBy('id')->firstOrFail()->forceFill(['state' => 'rejected'])->save();

    $this->post('/api/merchant/wallet/top-ups', topUpPayload(['bank_ref' => 'REF-MORE', 'slip' => Slips::png()]))
        ->assertCreated();
});

it('is throttled on both mounts', function (): void {
    foreach (['api/merchant/wallet/top-ups', 'api/mobile/v1/merchant/wallet/top-ups'] as $uri) {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate) => $candidate->uri() === $uri && in_array('POST', $candidate->methods(), true));

        expect($route)->not->toBeNull("route {$uri}")
            ->and($route->gatherMiddleware())->toContain('throttle:5,1');
    }
});

it('lists the platform accounts on the wallet, and refused claims with their reason for a week', function (): void {
    $this->actingAs($this->owner, 'merchant');

    $this->getJson('/api/merchant/wallet')
        ->assertSuccessful()
        ->assertJsonPath('data.bank_accounts.0.id', $this->account->id)
        ->assertJsonPath('data.bank_accounts.0.bank_name', 'mib')
        ->assertJsonPath('data.bank_accounts.0.account_no', '90501400021681001');

    $this->post('/api/merchant/wallet/top-ups', topUpPayload())->assertCreated();
    $claim = WalletTopUp::query()->sole();

    $claim->forceFill([
        'state' => 'rejected',
        'rejected_at' => now()->subDays(2),
        'rejected_reason' => 'No transfer of that amount reached the account.',
    ])->save();

    $this->getJson('/api/merchant/wallet')
        ->assertJsonCount(1, 'data.pending_top_ups')
        ->assertJsonPath('data.pending_top_ups.0.state', 'rejected')
        ->assertJsonPath('data.pending_top_ups.0.rejected_reason', 'No transfer of that amount reached the account.');

    $claim->forceFill(['rejected_at' => now()->subDays(8)])->save();

    $this->getJson('/api/merchant/wallet')->assertJsonCount(0, 'data.pending_top_ups');
});
