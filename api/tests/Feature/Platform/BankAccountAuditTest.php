<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\PlatformBankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression: the platform's settlement-receiving account could be rewritten
 * in place by ANY plain admin with zero audit trail — account_no was mutable
 * on the same row and platform_bank_accounts carried no created_by /
 * updated_by. Within a cache TTL every merchant settlement's
 * payment_instructions pointed at the new number. Now: writes are
 * superadmin-only, every write stamps the acting admin, and account_no is
 * immutable once created (replace = create-new + deactivate-old).
 */
function bankAuditPayload(array $overrides = []): array
{
    return [
        'bank_name' => 'bml',
        'account_no' => '7730000123456',
        'account_name' => 'Manfaa Pvt Ltd',
        'is_primary' => true,
        ...$overrides,
    ];
}

it('refuses bank account writes from a plain admin — reading stays open', function () {
    $this->actingAs(AdminUser::factory()->create(['role' => 'admin']), 'admin');

    $this->getJson('/api/admin/platform/bank-accounts')->assertOk();

    $this->postJson('/api/admin/platform/bank-accounts', bankAuditPayload())->assertForbidden();

    $account = PlatformBankAccount::query()->create([
        'bank_name' => 'bml', 'account_no' => '1', 'account_name' => 'A',
        'currency' => 'MVR', 'is_primary' => true, 'active' => true,
    ]);

    $this->patchJson("/api/admin/platform/bank-accounts/{$account->id}", ['account_no' => '999'])
        ->assertForbidden();

    expect($account->refresh()->account_no)->toBe('1');
});

it('stamps the acting superadmin on create and update', function () {
    $creator = AdminUser::factory()->create(['role' => 'superadmin']);
    $editor = AdminUser::factory()->create(['role' => 'superadmin']);

    $id = $this->actingAs($creator, 'admin')
        ->postJson('/api/admin/platform/bank-accounts', bankAuditPayload())
        ->assertCreated()
        ->assertJsonPath('data.created_by', $creator->id)
        ->assertJsonPath('data.updated_by', $creator->id)
        ->json('data.id');

    $this->actingAs($editor, 'admin')
        ->patchJson("/api/admin/platform/bank-accounts/{$id}", ['bank_name' => 'bml'])
        ->assertOk()
        ->assertJsonPath('data.bank_name', 'bml')
        ->assertJsonPath('data.created_by', $creator->id)
        ->assertJsonPath('data.updated_by', $editor->id);

    $row = PlatformBankAccount::query()->findOrFail($id);
    expect($row->created_by)->toBe($creator->id)->and($row->updated_by)->toBe($editor->id);
});

it('lets an account number be corrected in place, and still supports replacement', function () {
    $superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->actingAs($superadmin, 'admin');

    $id = $this->postJson('/api/admin/platform/bank-accounts', bankAuditPayload())
        ->assertCreated()->json('data.id');

    // OWNER DECISION (2026-08-19), reversing an earlier immutability rule:
    // the account number is always updatable. A typed-in account number gets
    // typed wrong, and forcing a correction through create-new-plus-
    // deactivate-old leaves a dead row that never received anything and an
    // operator wondering which of two accounts is real.
    $this->patchJson("/api/admin/platform/bank-accounts/{$id}", ['account_no' => '6660000999999'])
        ->assertOk()
        ->assertJsonPath('data.account_no', '6660000999999');

    expect(PlatformBankAccount::query()->findOrFail($id)->account_no)->toBe('6660000999999');

    // Put it back, and change a sibling field in the same breath — edit
    // forms echo every field, changed or not.
    $this->patchJson("/api/admin/platform/bank-accounts/{$id}", [
        'account_no' => '7730000123456',
        'account_name' => 'Manfaa Pvt Ltd (Operations)',
    ])->assertOk()->assertJsonPath('data.account_name', 'Manfaa Pvt Ltd (Operations)');

    // The sanctioned replacement path: new account takes primary, old one
    // deactivates — both rows survive, so old instructions stay explicable.
    $newId = $this->postJson('/api/admin/platform/bank-accounts', bankAuditPayload([
        'account_no' => '6660000999999',
        'bank_name' => 'mib',
    ]))->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');

    $this->patchJson("/api/admin/platform/bank-accounts/{$id}", ['active' => false])->assertOk();

    expect(PlatformBankAccount::query()->findOrFail($id)->active)->toBeFalse()
        ->and(PlatformBankAccount::query()->findOrFail($id)->account_no)->toBe('7730000123456')
        ->and(PlatformBankAccount::query()->findOrFail($newId)->is_primary)->toBeTrue()
        ->and(PlatformBankAccount::query()->where('active', true)->where('is_primary', true)->count())->toBe(1);
});
