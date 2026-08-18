<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantChangeRequest;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * MR9's admin surface, mirroring the store-review queue it sits beside: any
 * admin READS the queue, only a SUPERADMIN decides, and a refusal carries a
 * required reason.
 */
beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');

    $this->merchant = Merchant::factory()->create(['name' => 'Original Name']);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->admin = AdminUser::factory()->create(['role' => 'admin']);

    // One queued rename, submitted by the store itself.
    $this->actingAs($this->owner, 'merchant')
        ->patchJson('/api/merchant/profile', ['name' => 'Chai House'])
        ->assertStatus(202);

    $this->request = MerchantChangeRequest::query()->sole();

    app('auth')->forgetGuards();
});

it('answers an unauthenticated caller with 401 on every change-review route', function () {
    $id = $this->request->id;

    $this->getJson('/api/admin/change-requests')->assertUnauthorized();
    $this->getJson("/api/admin/change-requests/{$id}")->assertUnauthorized();
    $this->postJson("/api/admin/change-requests/{$id}/approve")->assertUnauthorized();
    $this->postJson("/api/admin/change-requests/{$id}/reject", ['reason' => 'no'])->assertUnauthorized();
});

it('lists the queue with the merchant and the diff, for any admin', function () {
    $response = $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/change-requests')
        ->assertOk()
        ->assertJsonPath('meta.status', 'pending')
        ->assertJsonPath('meta.counts.pending', 1)
        ->assertJsonPath('data.0.kind', 'profile')
        ->assertJsonPath('data.0.merchant.name', 'Original Name')
        ->assertJsonPath('data.0.submitted_by.id', $this->owner->id);

    // The before/after is the row's own, built from the submit-time
    // snapshot rather than from a live row that keeps moving.
    expect($response->json('data.0.changes'))->toBe([
        ['field' => 'name', 'from' => 'Original Name', 'to' => 'Chai House'],
    ]);

    $this->getJson("/api/admin/change-requests/{$this->request->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->request->id)
        ->assertJsonPath('data.proposed.name', 'Chai House')
        ->assertJsonPath('data.current.name', 'Original Name');
});

it('filters the queue by status and by kind', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create();

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/branches/{$branch->id}", ['name' => 'Renamed'])
        ->assertStatus(202);

    app('auth')->forgetGuards();

    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/change-requests?kind=branch_update')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.branch_id', $branch->id);

    $this->getJson('/api/admin/change-requests?status=approved')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.counts.pending', 2);

    // An unknown status is a 422, not a silently empty queue.
    $this->getJson('/api/admin/change-requests?status=whenever')->assertUnprocessable();
});

it('refuses approve and reject to a plain admin, and lets the superadmin decide', function () {
    $id = $this->request->id;

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/change-requests/{$id}/approve")
        ->assertForbidden();

    $this->postJson("/api/admin/change-requests/{$id}/reject", ['reason' => 'Not allowed to say this'])
        ->assertForbidden();

    expect($this->merchant->refresh()->name)->toBe('Original Name')
        ->and($this->request->refresh()->status)->toBe('pending');

    app('auth')->forgetGuards();

    $this->actingAs($this->superadmin, 'admin')
        ->postJson("/api/admin/change-requests/{$id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.reviewed_by', $this->superadmin->id);

    expect($this->merchant->refresh()->name)->toBe('Chai House');
});

it('requires a reason on a rejection and hands it back to the merchant', function () {
    $id = $this->request->id;

    $this->actingAs($this->superadmin, 'admin')
        ->postJson("/api/admin/change-requests/{$id}/reject", [])
        ->assertUnprocessable();

    $this->postJson("/api/admin/change-requests/{$id}/reject", ['reason' => 'Your registered name is different.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejected_reason', 'Your registered name is different.');

    expect($this->merchant->refresh()->name)->toBe('Original Name');

    // The store reads its own refusal on the profile it already loads.
    app('auth')->forgetGuards();

    $this->actingAs($this->owner->fresh(), 'merchant')
        ->getJson('/api/merchant/profile')
        ->assertOk()
        ->assertJsonPath('data.pending_change', null);
});

it('refuses to decide a request that has already been decided', function () {
    $id = $this->request->id;

    $this->actingAs($this->superadmin, 'admin')
        ->postJson("/api/admin/change-requests/{$id}/approve")
        ->assertOk();

    $this->postJson("/api/admin/change-requests/{$id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('code', 'change_not_pending');
});

it('refuses a queued branch change whose branch has gone, instead of reporting a success', function () {
    // Applying "nothing" silently would tell the reviewer the rename landed
    // on a branch that no longer exists — the merchant would then be told
    // their change is live when nothing about the store moved.
    $branch = MerchantBranch::factory()->for($this->merchant)->create(['name' => 'Doomed']);

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/branches/{$branch->id}", ['name' => 'Renamed'])
        ->assertStatus(202);

    $queued = MerchantChangeRequest::query()->where('kind', 'branch_update')->sole();

    // The FK nulls branch_id; the snapshot keeps saying which branch it was.
    $branch->delete();

    app('auth')->forgetGuards();

    $this->actingAs($this->superadmin, 'admin')
        ->postJson("/api/admin/change-requests/{$queued->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('code', 'branch_missing');

    expect($queued->refresh()->status)->toBe('pending');
});

it('serves the staged logo only to an admin or the store\'s own staff', function () {
    // The proposal is not public, whatever the store's status — a shopper
    // must never see a logo an admin has not approved.
    $this->getJson("/api/change-requests/{$this->request->id}/logo")->assertNotFound();

    $stranger = MerchantUser::factory()->for(Merchant::factory()->create())->owner()->create();

    $this->actingAs($stranger, 'merchant')
        ->getJson("/api/change-requests/{$this->request->id}/logo")
        ->assertNotFound();
});
