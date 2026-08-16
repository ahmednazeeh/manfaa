<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Domain\Payout\PayoutBatchBuilder;
use App\Domain\Platform\PlatformConfig;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->admin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->actingAs($this->admin, 'admin');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('lists every key typed, defaulted, ranged, and PATCHes with per-key validation', function () {
    $this->getJson('/api/admin/platform/settings')
        ->assertOk()
        ->assertJsonPath('data.min_payout_laari.value', 10000)
        ->assertJsonPath('data.min_payout_laari.overridden', false)
        ->assertJsonPath('data.settlement_due_days.value', 15)
        ->assertJsonPath('data.write_off_days.value', 90)
        ->assertJsonPath('data.default_validation_window_days.value', 3)
        ->assertJsonPath('data.default_min_eligible_laari.value', 5000);

    $this->patchJson('/api/admin/platform/settings/settlement_due_days', ['value' => 10])
        ->assertOk()
        ->assertJsonPath('data.settlement_due_days.value', 10)
        ->assertJsonPath('data.settlement_due_days.overridden', true);

    // Per-key integer ranges.
    $this->patchJson('/api/admin/platform/settings/min_payout_laari', ['value' => -1])->assertStatus(422);
    $this->patchJson('/api/admin/platform/settings/min_payout_laari', ['value' => 1000001])->assertStatus(422);
    $this->patchJson('/api/admin/platform/settings/settlement_due_days', ['value' => 0])->assertStatus(422);
    $this->patchJson('/api/admin/platform/settings/settlement_due_days', ['value' => 61])->assertStatus(422);
    $this->patchJson('/api/admin/platform/settings/write_off_days', ['value' => 29])->assertStatus(422);
    $this->patchJson('/api/admin/platform/settings/write_off_days', ['value' => 366])->assertStatus(422);
    $this->patchJson('/api/admin/platform/settings/default_validation_window_days', ['value' => 31])->assertStatus(422);
    $this->patchJson('/api/admin/platform/settings/default_validation_window_days', ['value' => 'ten'])->assertStatus(422);
    $this->patchJson('/api/admin/platform/settings/no_such_key', ['value' => 1])->assertStatus(404);
});

it('excludes a customer below a raised min_payout_laari from the next batch', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-26T12:00:00+05:00'));
    $this->seed(LedgerAccountSeeder::class);

    $merchant = Merchant::factory()->create(['validation_window_days' => 3, 'min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $creditor = MerchantUser::factory()->for($merchant)->owner()->create();

    $bank = ['payout_bank' => 'BML', 'payout_account' => '7730000000001', 'payout_account_name' => 'Holder'];
    $under = Customer::factory()->create($bank + ['customer_code' => '111111']);
    $over = Customer::factory()->create($bank + ['payout_account' => '7730000000002', 'customer_code' => '222222']);

    $transitions = app(TransitionService::class);

    // 750,000 laari at 200bp -> 15,000 cashback; 1,250,000 -> 25,000.
    foreach ([[$under, 750000], [$over, 1250000]] as [$customer, $eligible]) {
        $transaction = app(ManualCreditService::class)->credit(
            $merchant, $creditor, $customer->customer_code,
            'INV-'.$customer->customer_code, Laari::of($eligible), null,
            CarbonImmutable::now('UTC')->subHour(),
        );
        $transitions->makePayable($transaction, Actor::system());
        $transitions->confirm($transaction, Actor::system());

        // Pin the confirmation before the chosen cutoff.
        DB::table('transaction_events')
            ->where('transaction_id', $transaction->id)
            ->where('to_state', TransactionState::Confirmed->value)
            ->update(['created_at' => CarbonImmutable::parse('2026-08-20T12:00:00+05:00')->utc()]);
    }

    $cutoff = CarbonImmutable::parse('2026-08-24T23:59:59+05:00');

    // Default minimum (10,000): both customers are in.
    $draft = app(PayoutBatchBuilder::class)->buildDraft($cutoff, $this->admin);
    expect($draft->customer_count)->toBe(2)->and($draft->total_laari)->toBe(15000 + 25000);
    app(PayoutBatchBuilder::class)->cancelDraft($draft);

    // Raised to 20,000: the 15,000-laari customer drops out and carries forward.
    app(PlatformConfig::class)->set('min_payout_laari', 20000);

    $raised = app(PayoutBatchBuilder::class)->buildDraft($cutoff, $this->admin);
    expect($raised->customer_count)->toBe(1)
        ->and($raised->total_laari)->toBe(25000)
        ->and($raised->items()->sole()->customer_id)->toBe($over->id);
});

it('offsets due_at by the configured settlement_due_days in makePayable', function () {
    app(PlatformConfig::class)->set('settlement_due_days', 10);

    $merchant = Merchant::factory()->create();
    $transaction = Transaction::factory()->for($merchant)->create(['state' => 'awaiting_validation']);

    Carbon::setTestNow($start = CarbonImmutable::parse('2026-08-14T10:00:00+05:00'));

    app(TransitionService::class)->makePayable($transaction, Actor::system());
    $transaction->refresh();

    expect($transaction->due_at->utc()->toIso8601String())
        ->toBe($start->addDays(10)->utc()->toIso8601String());
});

it('writes off at the configured write_off_days horizon instead of 90', function () {
    $this->seed(LedgerAccountSeeder::class);
    app(PlatformConfig::class)->set('write_off_days', 60);

    $clockStart = CarbonImmutable::parse('2026-05-01T09:00:00+05:00')->utc();
    $merchant = Merchant::factory()->create();

    $makePayable = function (CarbonImmutable $dueAt) use ($merchant): Transaction {
        $transaction = Transaction::factory()->for($merchant)->create([
            'state' => 'payable_unfunded',
            'clock_start_at' => $dueAt->subDays(15),
            'due_at' => $dueAt,
            'cashback_laari' => 2000,
            'fee_laari' => 750,
            'fee_gst_laari' => 0,
        ]);
        app(Postings::class)->accrue(2000, 750, 0, referenceId: $transaction->id);

        return $transaction;
    };

    $now = $clockStart->addDays(100);
    $at61 = $makePayable($now->subDays(61)); // 61 days past due — written off under a 60d horizon
    $at59 = $makePayable($now->subDays(59)); // 59 days past due — untouched

    Carbon::setTestNow($now);
    $this->artisan('manfaa:write-off')->assertExitCode(0);

    expect($at61->refresh()->state)->toBe(TransactionState::WrittenOff)
        ->and($at61->reason_code)->toBe('merchant_default_90d')
        ->and($at59->refresh()->state)->toBe(TransactionState::PayableUnfunded);
});

it('fills merchant defaults from platform settings only when the caller did not set them', function () {
    // Unset: the hardcoded defaults. The window a NEW store starts on is
    // its own setting (2 days), separate from the ceiling a merchant may
    // raise themselves to — one is what they get, the other is how far
    // they may go.
    $vanilla = Merchant::query()->create(['name' => 'Default Corner', 'slug' => 'default-corner', 'status' => 'active']);
    expect($vanilla->validation_window_days)->toBe(2)->and($vanilla->min_eligible_laari)->toBe(5000);

    // Raising the ceiling alone does NOT move what a new store starts on.
    app(PlatformConfig::class)->set('default_validation_window_days', 7);
    app(PlatformConfig::class)->set('default_min_eligible_laari', 10000);

    $ceilingOnly = Merchant::query()->create(['name' => 'Ceiling Corner', 'slug' => 'ceiling-corner', 'status' => 'active']);
    expect($ceilingOnly->validation_window_days)->toBe(2)->and($ceilingOnly->min_eligible_laari)->toBe(10000);

    app(PlatformConfig::class)->set('new_merchant_validation_window_days', 5);

    $configured = Merchant::query()->create(['name' => 'Config Corner', 'slug' => 'config-corner', 'status' => 'active']);
    expect($configured->validation_window_days)->toBe(5)->and($configured->min_eligible_laari)->toBe(10000);

    // A new-store window above the ceiling is clamped to it, so a store is
    // never created outside the range its own preferences screen allows.
    app(PlatformConfig::class)->set('default_validation_window_days', 3);
    app(PlatformConfig::class)->set('new_merchant_validation_window_days', 9);

    $clamped = Merchant::query()->create(['name' => 'Clamped Corner', 'slug' => 'clamped-corner', 'status' => 'active']);
    expect($clamped->validation_window_days)->toBe(3);

    // An explicit value always beats the platform default.
    $explicit = Merchant::query()->create([
        'name' => 'Explicit Corner', 'slug' => 'explicit-corner', 'status' => 'active',
        'validation_window_days' => 1, 'min_eligible_laari' => 100,
    ]);
    expect($explicit->validation_window_days)->toBe(1)->and($explicit->min_eligible_laari)->toBe(100);
});
