<?php

use App\Domain\Discovery\DiscoveryService;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantNotice;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rejects unauthenticated access to the manual suspend endpoint', function () {
    $merchant = Merchant::factory()->create();

    $this->postJson("/api/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Fraudulent crediting.'])
        ->assertUnauthorized();

    expect($merchant->refresh()->status)->toBe('active');
});

it('reserves manual suspension for superadmins', function () {
    $merchant = Merchant::factory()->create();

    $this->actingAs(AdminUser::factory()->create(), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Fraudulent crediting.'])
        ->assertForbidden();

    expect($merchant->refresh()->status)->toBe('active');
});

it('requires a reason', function () {
    $merchant = Merchant::factory()->create();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/suspend")
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($merchant->refresh()->status)->toBe('active');
});

it('suspends an active merchant and records a manual, reason-carrying notice', function () {
    $merchant = Merchant::factory()->create();
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Crediting phantom receipts; under investigation.'])
        ->assertOk()
        ->assertJsonPath('data.id', $merchant->id)
        ->assertJsonPath('data.status', 'suspended');

    expect($merchant->refresh()->status)->toBe('suspended');

    $notice = MerchantNotice::query()->where('type', 'suspended')->sole();
    expect($notice->merchant_id)->toBe($merchant->id)
        ->and($notice->payload['manual'])->toBeTrue()
        ->and($notice->payload['reason'])->toBe('Crediting phantom receipts; under investigation.')
        ->and($notice->payload['admin_id'])->toBe($admin->id);
});

it('refuses to suspend a merchant that is not active', function () {
    $merchant = Merchant::factory()->suspended()->create();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Twice for good measure.'])
        ->assertStatus(409);

    expect($merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->count())->toBe(0);
});

it('drops the warm public read model so the store vanishes from the feed at once, and reinstate restores it', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Conduct Store',
        'slug' => 'conduct-store',
        'category' => 'grocery',
        'channel' => 'in_store',
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::now('UTC')->subYear(),
        'effective_to' => null,
    ]);

    // A visitor warms both cached keys, exactly as a real read would.
    $data = $this->getJson('/api/discover')->assertOk()->json('data');
    expect(collect($data['in_store'])->pluck('slug'))->toContain('conduct-store');
    $this->getJson('/api/discover/merchants/conduct-store')->assertOk();

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Conduct review.'])
        ->assertOk();

    // Gone this second — shelves, directory and store page — not after the
    // 60-second TTL.
    $data = $this->getJson('/api/discover')->assertOk()->json('data');
    expect($data['in_store'])->toBe([]);
    expect($this->getJson('/api/discover/merchants')->assertOk()->json('meta.total'))->toBe(0);
    $this->getJson('/api/discover/merchants/conduct-store')->assertNotFound();

    // And the manual way back restores the store to the public feed.
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/reinstate", ['note' => 'Review closed, no findings.'])
        ->assertOk();

    $data = $this->getJson('/api/discover')->assertOk()->json('data');
    expect(collect($data['in_store'])->pluck('slug'))->toContain('conduct-store');
    $this->getJson('/api/discover/merchants/conduct-store')->assertOk();
});

it('is never undone by the automatic reinstate sweep', function () {
    // A manually suspended store typically owes nothing overdue — precisely
    // the shape the automatic sweep would reopen if it mistook the manual
    // notice for its own.
    $merchant = Merchant::factory()->create();

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Conduct review.'])
        ->assertOk();

    $this->artisan('manfaa:reinstate')->assertExitCode(0);

    expect($merchant->refresh()->status)->toBe('suspended')
        ->and(MerchantNotice::query()->where('type', 'reinstated')->count())->toBe(0);
});

it('drops both cache keys directly', function () {
    $merchant = Merchant::factory()->create();

    Cache::put(DiscoveryService::CACHE_KEY, ['entries' => [], 'categories' => []], 60);
    Cache::put(DiscoveryService::STORE_CACHE_PREFIX.$merchant->slug, ['name' => 'stale'], 60);

    $this->actingAs(AdminUser::factory()->create(['role' => 'superadmin']), 'admin')
        ->postJson("/api/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Conduct review.'])
        ->assertOk();

    expect(Cache::has(DiscoveryService::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(DiscoveryService::STORE_CACHE_PREFIX.$merchant->slug))->toBeFalse();
});
