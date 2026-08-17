<?php

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Standing\NoticeRecorder;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rejects unauthenticated access to the manual reinstate endpoint', function () {
    $merchant = Merchant::factory()->suspended()->create();

    $this->postJson("/api/admin/merchants/{$merchant->id}/reinstate", ['note' => 'x'])
        ->assertUnauthorized();
});

it('reserves manual reinstatement for superadmins', function () {
    $merchant = Merchant::factory()->suspended()->create();

    // A plain admin may read the standing screen but not reopen a store.
    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate", ['note' => 'Debt settled.'])
        ->assertForbidden();

    expect($merchant->refresh()->status)->toBe('suspended');
});

it('requires a note', function () {
    $merchant = Merchant::factory()->suspended()->create();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate")
        ->assertStatus(422)
        ->assertJsonValidationErrors('note');

    expect($merchant->refresh()->status)->toBe('suspended');
});

it('reinstates a suspended merchant and records a manual, note-carrying notice', function () {
    $merchant = Merchant::factory()->suspended()->create();
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate", ['note' => 'Debt settled out of band; approved by finance.'])
        ->assertOk()
        ->assertJsonPath('data.id', $merchant->id)
        ->assertJsonPath('data.status', 'active');

    expect($merchant->refresh()->status)->toBe('active');

    $notice = MerchantNotice::query()->where('type', 'reinstated')->sole();
    expect($notice->merchant_id)->toBe($merchant->id)
        ->and($notice->payload['manual'])->toBeTrue()
        ->and($notice->payload['note'])->toBe('Debt settled out of band; approved by finance.')
        ->and($notice->payload['admin_id'])->toBe($admin->id);
});

it('refuses to reinstate a merchant that is not suspended', function () {
    $merchant = Merchant::factory()->create();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate", ['note' => 'x'])
        ->assertStatus(409);

    expect($merchant->refresh()->status)->toBe('active')
        ->and(MerchantNotice::query()->count())->toBe(0);
});

it('is the only path back after write-off: the automatic sweep keeps skipping, the manual action succeeds', function () {
    // A merchant suspended by the automatic control whose overdue debt was
    // then cleared by the 90-day write-off — by DEFAULT, not by payment.
    $merchant = Merchant::factory()->suspended()->create();
    app(NoticeRecorder::class)->record($merchant->id, 'suspended', []);
    Transaction::factory()->for($merchant)->create(['state' => 'written_off']);

    // Regression for the earlier fix: no overdue payables remain, yet the
    // automatic sweep must not follow the write-off.
    $this->artisan('manfaa:reinstate')->assertExitCode(0);
    expect($merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'reinstated')->count())->toBe(0);

    // The deliberate admin action is the path back (PLAN.md open policy —
    // manual-only until decided).
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate", ['note' => 'Defaulted balance recovered via legal settlement.'])
        ->assertOk();

    expect($merchant->refresh()->status)->toBe('active');

    // And the next sweep neither re-suspends nor double-writes notices.
    $this->artisan('manfaa:reinstate')->assertExitCode(0);
    expect($merchant->refresh()->status)->toBe('active')
        ->and(MerchantNotice::query()->where('type', 'reinstated')->count())->toBe(1);
});

it('drops the storefront read model so the store reappears at once', function () {
    $merchant = Merchant::factory()->suspended()->create();

    // Both keys warm, as a live storefront read leaves them — here holding
    // the store's ABSENCE, which is exactly as stale as a stale rate.
    Cache::put(DiscoveryService::CACHE_KEY, ['entries' => [], 'categories' => []], 60);
    Cache::put(DiscoveryService::STORE_CACHE_PREFIX.$merchant->slug, ['name' => 'stale'], 60);

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate", ['note' => 'Debt settled out of band.'])
        ->assertOk();

    expect(Cache::has(DiscoveryService::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(DiscoveryService::STORE_CACHE_PREFIX.$merchant->slug))->toBeFalse();
});
