<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant');
});

it('creates, lists, updates and deletes a branch', function () {
    $id = $this->postJson('/api/merchant/branches', [
        'name' => 'Hulhumalé Outlet',
        'address' => 'Nirolhu Magu',
        'lat' => 4.2105,
        'lng' => 73.5409,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Hulhumalé Outlet')
        ->assertJsonPath('data.lat', 4.2105)
        ->assertJsonPath('data.lng', 73.5409)
        ->json('data.id');

    $this->getJson('/api/merchant/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->patchJson("/api/merchant/branches/{$id}", ['name' => 'Hulhumalé Flagship'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Hulhumalé Flagship')
        ->assertJsonPath('data.lat', 4.2105);

    // Clearing the coordinates works as a PAIR.
    $this->patchJson("/api/merchant/branches/{$id}", ['lat' => null, 'lng' => null])
        ->assertOk()
        ->assertJsonPath('data.lat', null)
        ->assertJsonPath('data.lng', null);

    $this->deleteJson("/api/merchant/branches/{$id}")->assertNoContent();

    expect(MerchantBranch::query()->count())->toBe(0);
});

it('rejects out-of-range and lone coordinates', function () {
    $base = ['name' => 'Bad Branch'];

    $this->postJson('/api/merchant/branches', [...$base, 'lat' => 91, 'lng' => 73])->assertUnprocessable();
    $this->postJson('/api/merchant/branches', [...$base, 'lat' => -91, 'lng' => 73])->assertUnprocessable();
    $this->postJson('/api/merchant/branches', [...$base, 'lat' => 4, 'lng' => 181])->assertUnprocessable();
    $this->postJson('/api/merchant/branches', [...$base, 'lat' => 4, 'lng' => -181])->assertUnprocessable();

    // The pair rule: one coordinate alone is meaningless.
    $this->postJson('/api/merchant/branches', [...$base, 'lat' => 4.17])->assertUnprocessable();
    $this->postJson('/api/merchant/branches', [...$base, 'lng' => 73.5])->assertUnprocessable();

    expect(MerchantBranch::query()->count())->toBe(0);
});

it('refuses to unpair coordinates on update', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create([
        'lat' => 4.1755, 'lng' => 73.5093,
    ]);

    $this->patchJson("/api/merchant/branches/{$branch->id}", ['lat' => null])
        ->assertUnprocessable();

    expect((float) $branch->refresh()->lat)->toBe(4.1755);
});

it('blocks deleting a branch that transactions reference', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create();

    Transaction::factory()->create([
        'merchant_id' => $this->merchant->id,
        'branch_id' => $branch->id,
        'customer_id' => Customer::factory()->create()->id,
    ]);

    $this->deleteJson("/api/merchant/branches/{$branch->id}")
        ->assertConflict()
        ->assertJsonPath('code', 'branch_referenced');

    expect(MerchantBranch::query()->whereKey($branch->id)->exists())->toBeTrue();

    // The soft alternative: an unreferenced branch still deletes fine.
    $unreferenced = MerchantBranch::factory()->for($this->merchant)->create();
    $this->deleteJson("/api/merchant/branches/{$unreferenced->id}")->assertNoContent();
});

it('scopes branch management to the authenticated merchant', function () {
    $foreign = MerchantBranch::factory()->create(); // other merchant

    $this->patchJson("/api/merchant/branches/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->deleteJson("/api/merchant/branches/{$foreign->id}")->assertNotFound();

    expect($foreign->refresh()->name)->not->toBe('Hijacked');
});
