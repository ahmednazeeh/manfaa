<?php

declare(strict_types=1);

use App\Domain\Platform\BankAccountService;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PlatformBankAccount;
use App\Models\Settlement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bankAccountPayload(array $overrides = []): array
{
    return [
        'bank_name' => 'Bank of Maldives',
        'account_no' => '7730000123456',
        'account_name' => 'Manfaa Pvt Ltd',
        'is_primary' => true,
        ...$overrides,
    ];
}

/**
 * A settlement row awaiting payment for one merchant, plus a logged-in
 * merchant user to view it.
 */
function settlementForMerchantView(): array
{
    $merchant = Merchant::factory()->create();
    $user = MerchantUser::factory()->for($merchant)->owner()->create();

    $settlement = Settlement::query()->create([
        'merchant_id' => $merchant->id,
        'reference' => 'ST-2026-00042',
        'state' => 'awaiting_payment',
        'funding_method' => 'bank',
        'amount_due_laari' => 11825,
        'currency' => 'MVR',
    ]);

    return [$user, $settlement];
}

it('enforces exactly one active primary at the database via the partial unique index', function () {
    DB::table('platform_bank_accounts')->insert([
        'bank_name' => 'BML', 'account_no' => '1', 'account_name' => 'A',
        'currency' => 'MVR', 'is_primary' => true, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A second active primary collides; an inactive primary and an active
    // non-primary both slot in freely.
    DB::table('platform_bank_accounts')->insert([
        'bank_name' => 'MIB', 'account_no' => '2', 'account_name' => 'B',
        'currency' => 'MVR', 'is_primary' => true, 'active' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('platform_bank_accounts')->insert([
        'bank_name' => 'MIB', 'account_no' => '3', 'account_name' => 'C',
        'currency' => 'MVR', 'is_primary' => false, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('platform_bank_accounts')->insert([
        'bank_name' => 'MIB', 'account_no' => '4', 'account_name' => 'D',
        'currency' => 'MVR', 'is_primary' => true, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('manages bank accounts over the admin endpoints, promotion demoting the incumbent primary', function () {
    // Writes are superadmin-only; see BankAccountAuditTest for the gating.
    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin');

    $first = $this->postJson('/api/admin/platform/bank-accounts', bankAccountPayload())
        ->assertCreated()
        ->assertJsonPath('data.bank_name', 'Bank of Maldives')
        ->assertJsonPath('data.is_primary', true)
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.currency', 'MVR')
        ->json('data.id');

    // A second primary demotes the first — never two active primaries.
    $second = $this->postJson('/api/admin/platform/bank-accounts', bankAccountPayload([
        'bank_name' => 'Maldives Islamic Bank',
        'account_no' => '9990000654321',
    ]))->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');

    expect(PlatformBankAccount::query()->findOrFail($first)->is_primary)->toBeFalse()
        ->and(PlatformBankAccount::query()->where('active', true)->where('is_primary', true)->count())->toBe(1);

    // PATCH promotes the first back, demoting the second.
    $this->patchJson("/api/admin/platform/bank-accounts/{$first}", ['is_primary' => true])
        ->assertOk()
        ->assertJsonPath('data.is_primary', true);

    expect(PlatformBankAccount::query()->findOrFail($second)->is_primary)->toBeFalse();

    $this->getJson('/api/admin/platform/bank-accounts')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('requires an authenticated admin for bank account management', function () {
    $this->getJson('/api/admin/platform/bank-accounts')->assertUnauthorized();
    $this->postJson('/api/admin/platform/bank-accounts', bankAccountPayload())->assertUnauthorized();
});

it('embeds the active primary account in merchant settlement payment instructions', function () {
    app(BankAccountService::class)->create([
        'bank_name' => 'Bank of Maldives',
        'account_no' => '7730000123456',
        'account_name' => 'Manfaa Pvt Ltd',
        'is_primary' => true,
    ]);

    [$user, $settlement] = settlementForMerchantView();
    $this->actingAs($user, 'merchant');

    $this->getJson("/api/merchant/settlements/{$settlement->id}")
        ->assertOk()
        ->assertJsonPath('data.payment_instructions.reference', 'ST-2026-00042')
        ->assertJsonPath('data.payment_instructions.amount_due_laari', 11825)
        ->assertJsonPath('data.payment_instructions.amount_due_mvr', '118.25')
        ->assertJsonPath('data.payment_instructions.needs_configuration', false)
        ->assertJsonPath('data.payment_instructions.bank_account.bank_name', 'Bank of Maldives')
        ->assertJsonPath('data.payment_instructions.bank_account.account_no', '7730000123456')
        ->assertJsonPath('data.payment_instructions.bank_account.account_name', 'Manfaa Pvt Ltd');
});

it('flags needs_configuration with a null account when no active primary exists — details are never invented', function () {
    // An inactive primary and an active non-primary must both be ignored.
    app(BankAccountService::class)->create([
        'bank_name' => 'Old Bank', 'account_no' => '1', 'account_name' => 'Old',
        'is_primary' => true, 'active' => false,
    ]);
    app(BankAccountService::class)->create([
        'bank_name' => 'Side Bank', 'account_no' => '2', 'account_name' => 'Side',
        'is_primary' => false,
    ]);

    [$user, $settlement] = settlementForMerchantView();
    $this->actingAs($user, 'merchant');

    $this->getJson("/api/merchant/settlements/{$settlement->id}")
        ->assertOk()
        ->assertJsonPath('data.payment_instructions.bank_account', null)
        ->assertJsonPath('data.payment_instructions.needs_configuration', true);
});
