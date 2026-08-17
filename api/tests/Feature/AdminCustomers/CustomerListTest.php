<?php

use App\Models\AdminUser;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('requires an admin session to read the customer list and detail', function () {
    $customer = Customer::factory()->create();

    $this->getJson('/api/admin/customers')->assertUnauthorized();
    $this->getJson("/api/admin/customers/{$customer->id}")->assertUnauthorized();
});

it('lists customers for a plain admin with the support columns and no account number', function () {
    $customer = Customer::factory()->create([
        'name' => 'Aishath Waheedha',
        'phone' => '+9607712345',
        'customer_code' => '123456',
        'payout_bank' => 'bml',
        'payout_account' => '7730000123456',
        'payout_account_name' => 'Aishath Waheedha',
    ]);
    Customer::factory()->create(['payout_bank' => null, 'payout_account' => null, 'payout_account_name' => null]);

    $response = $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->getJson('/api/admin/customers')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $customer->id);

    expect($row)->toMatchArray([
        'customer_code' => '123456',
        'name' => 'Aishath Waheedha',
        'phone' => '+9607712345',
        'status' => 'active',
        'kyc_status' => 'none',
        'has_payout_account' => true,
    ])
        ->and($row['created_at'])->not->toBeNull()
        // Standard paginator envelope, like every other admin queue.
        ->and($response->json('meta.total'))->toBe(2);

    // The account NUMBER stays off the list — the detail shows it masked.
    expect($response->getContent())->not->toContain('7730000123456');
});

it('searches by name, phone in any typed form, and customer code', function () {
    $target = Customer::factory()->create([
        'name' => 'Hassan Zahir',
        'phone' => '+9607712345',
        'customer_code' => '345678',
    ]);
    Customer::factory()->create([
        'name' => 'Mariyam Nadha',
        'phone' => '+9609990001',
        'customer_code' => '198765',
    ]);

    $admin = AdminUser::factory()->create();

    $byName = $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/customers?q=zahi')->assertOk()->json('data');
    expect($byName)->toHaveCount(1)
        ->and($byName[0]['id'])->toBe($target->id);

    // Seven local digits with punctuation — folded into the stored +960
    // shape before matching, the same normalise-before-use rule as sign-in.
    $byPhone = $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/customers?q='.urlencode('771-2345'))->assertOk()->json('data');
    expect($byPhone)->toHaveCount(1)
        ->and($byPhone[0]['id'])->toBe($target->id);

    // A partial number read off a call screen still finds the account.
    $byPartial = $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/customers?q=771234')->assertOk()->json('data');
    expect(collect($byPartial)->pluck('id'))->toContain($target->id);

    $byCode = $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/customers?q=345678')->assertOk()->json('data');
    expect(collect($byCode)->pluck('id'))->toContain($target->id);

    $none = $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/customers?q=nomatchatall')->assertOk()->json('data');
    expect($none)->toBeEmpty();
});
