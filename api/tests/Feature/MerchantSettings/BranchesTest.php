<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantChangeRequest;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Approvals;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant');
});

it('creates, lists, updates and deletes a branch — each through review (MR9)', function () {
    // A branch is an address a shopper travels to, so a LIVE store's estate
    // now moves only after an admin approves. Every write answers 202 with
    // the queued request; the estate follows at approval.
    $this->postJson('/api/merchant/branches', [
        'name' => 'Hulhumalé Outlet',
        'address' => 'Nirolhu Magu',
        'lat' => 4.2105,
        'lng' => 73.5409,
    ])->assertStatus(202)
        ->assertJsonPath('data.status', 'pending_review')
        ->assertJsonPath('data.change_request.kind', 'branch_create')
        ->assertJsonPath('data.change_request.proposed.name', 'Hulhumalé Outlet');

    expect(MerchantBranch::query()->count())->toBe(0);

    Approvals::approveAll($this->merchant);

    $id = $this->getJson('/api/merchant/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Hulhumalé Outlet')
        ->assertJsonPath('data.0.lat', 4.2105)
        ->assertJsonPath('data.0.lng', 73.5409)
        ->assertJsonCount(0, 'meta.pending_changes')
        ->json('data.0.id');

    $this->patchJson("/api/merchant/branches/{$id}", ['name' => 'Hulhumalé Flagship'])
        ->assertStatus(202)
        ->assertJsonPath('data.change_request.kind', 'branch_update')
        ->assertJsonPath('data.change_request.branch_id', $id);

    // The panels render the wait from the index, which carries the proposal.
    $this->getJson('/api/merchant/branches')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Hulhumalé Outlet')
        ->assertJsonCount(1, 'meta.pending_changes')
        ->assertJsonPath('meta.pending_changes.0.proposed.name', 'Hulhumalé Flagship');

    Approvals::approveAll($this->merchant);

    $this->getJson('/api/merchant/branches')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Hulhumalé Flagship')
        ->assertJsonPath('data.0.lat', 4.2105);

    // Clearing the coordinates works as a PAIR.
    $this->patchJson("/api/merchant/branches/{$id}", ['lat' => null, 'lng' => null])
        ->assertStatus(202);

    Approvals::approveAll($this->merchant);

    $this->getJson('/api/merchant/branches')
        ->assertOk()
        ->assertJsonPath('data.0.lat', null)
        ->assertJsonPath('data.0.lng', null);

    $this->deleteJson("/api/merchant/branches/{$id}")
        ->assertStatus(202)
        ->assertJsonPath('data.change_request.kind', 'branch_delete')
        // A removal proposes nothing; what the reviewer decides on is the
        // branch as it stands, which the snapshot carries.
        ->assertJsonPath('data.change_request.current.name', 'Hulhumalé Flagship');

    expect(MerchantBranch::query()->count())->toBe(1);

    Approvals::approveAll($this->merchant);

    expect(MerchantBranch::query()->count())->toBe(0);
});

it('re-saving an untouched branch queues nothing', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create(['name' => 'Majeedhee Magu']);

    // The panel PATCHes the whole dialog, so a save with nothing moved must
    // be a 200, not a review queue somebody has to work through.
    $this->patchJson("/api/merchant/branches/{$branch->id}", ['name' => 'Majeedhee Magu'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Majeedhee Magu');

    expect(MerchantChangeRequest::query()->count())->toBe(0);
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

    // Validation runs BEFORE the review gate: a malformed change is refused
    // to the person who typed it, not parked in a queue for an admin.
    $this->patchJson("/api/merchant/branches/{$branch->id}", ['lat' => null])
        ->assertUnprocessable();

    expect((float) $branch->refresh()->lat)->toBe(4.1755)
        ->and(MerchantChangeRequest::query()->count())->toBe(0);
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

    expect(MerchantBranch::query()->whereKey($branch->id)->exists())->toBeTrue()
        // Refused at SUBMIT, so a removal that can never be honoured never
        // reaches the queue at all.
        ->and(MerchantChangeRequest::query()->count())->toBe(0);

    // The soft alternative: an unreferenced branch still goes (via review).
    $unreferenced = MerchantBranch::factory()->for($this->merchant)->create();
    $this->deleteJson("/api/merchant/branches/{$unreferenced->id}")->assertStatus(202);

    Approvals::approveAll($this->merchant);

    expect(MerchantBranch::query()->whereKey($unreferenced->id)->exists())->toBeFalse();
});

it('scopes branch management to the authenticated merchant', function () {
    $foreign = MerchantBranch::factory()->create(); // other merchant

    $this->patchJson("/api/merchant/branches/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->deleteJson("/api/merchant/branches/{$foreign->id}")->assertNotFound();

    expect($foreign->refresh()->name)->not->toBe('Hijacked');
});
