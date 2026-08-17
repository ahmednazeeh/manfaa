<?php

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('shows the detail record: profile, MASKED payout account, balance sums and live device count', function () {
    $customer = Customer::factory()->create([
        'name' => 'Aishath Waheedha',
        'phone' => '+9607712345',
        'email' => 'aishath@example.mv',
        'payout_bank' => 'bml',
        'payout_account' => '7730000123456',
        'payout_account_name' => 'Aishath Waheedha',
    ]);

    $merchant = Merchant::factory()->create();
    Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'state' => 'confirmed',
        'cashback_laari' => 3000,
    ]);
    Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'state' => 'awaiting_validation',
        'cashback_laari' => 1500,
    ]);

    // One live device and one expired row — only the live one is a device.
    $customer->createToken('customer: iPhone 14', ['mobile:customer'], now()->addDays(30));
    $customer->createToken('customer: old phone', ['mobile:customer'], now()->subDay());

    $response = $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson("/api/admin/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.customer_code', $customer->customer_code)
        ->assertJsonPath('data.name', 'Aishath Waheedha')
        ->assertJsonPath('data.phone', '+9607712345')
        ->assertJsonPath('data.email', 'aishath@example.mv')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.kyc_status', 'none')
        ->assertJsonPath('data.has_payout_account', true)
        ->assertJsonPath('data.payout_account.bank', 'bml')
        ->assertJsonPath('data.payout_account.account_masked', '•••• 3456')
        ->assertJsonPath('data.payout_account.account_name', 'Aishath Waheedha')
        ->assertJsonPath('data.balance.currency', 'MVR')
        ->assertJsonPath('data.balance.confirmed_laari', 3000)
        ->assertJsonPath('data.balance.pending_laari', 1500)
        ->assertJsonPath('data.devices_count', 1);

    // The full account number never crosses the admin boundary — the same
    // stance as the customer's own payout screens.
    expect($response->getContent())->not->toContain('7730000123456');
});

it('shows no payout account block when none is on file', function () {
    $customer = Customer::factory()->create([
        'payout_bank' => null,
        'payout_account' => null,
        'payout_account_name' => null,
    ]);

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson("/api/admin/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.has_payout_account', false)
        ->assertJsonPath('data.payout_account', null)
        ->assertJsonPath('data.devices_count', 0);
});
