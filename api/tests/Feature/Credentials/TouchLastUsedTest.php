<?php

use App\Domain\Credentials\CredentialService;
use App\Listeners\TouchCredentialLastUsed;
use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PosVendor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Route::get('/credential-test/ping', fn () => response()->json(['ok' => true]))
        ->middleware('auth:sanctum');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('stamps last_used_at on the credential when its token authenticates', function () {
    $now = CarbonImmutable::parse('2026-08-14T12:00:00+00:00');
    Carbon::setTestNow($now);

    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $vendor = PosVendor::query()->create(['name' => 'TillPoint']);

    $issued = app(CredentialService::class)->issue($merchant, $vendor, ['rates:read'], $admin);
    expect($issued->credential->last_used_at)->toBeNull();

    $this->withHeader('Authorization', 'Bearer '.$issued->plainTextToken)
        ->getJson('/credential-test/ping')
        ->assertOk();

    expect($issued->credential->refresh()->last_used_at?->toIso8601String())
        ->toBe('2026-08-14T12:00:00+00:00');
});

it('throttles the stamp: a fresh timestamp is not rewritten, a stale one is', function () {
    $now = CarbonImmutable::parse('2026-08-14T12:00:00+00:00');
    Carbon::setTestNow($now);

    $admin = AdminUser::factory()->create();
    $merchant = Merchant::factory()->create();
    $vendor = PosVendor::query()->create(['name' => 'TillPoint']);

    $issued = app(CredentialService::class)->issue($merchant, $vendor, ['rates:read'], $admin);

    // Seen 10 seconds ago — inside the throttle window, no write.
    $fresh = $now->subSeconds(10);
    $issued->credential->forceFill(['last_used_at' => $fresh])->save();

    $this->withHeader('Authorization', 'Bearer '.$issued->plainTextToken)
        ->getJson('/credential-test/ping')
        ->assertOk();

    expect($issued->credential->refresh()->last_used_at?->toIso8601String())
        ->toBe($fresh->toIso8601String());

    // Seen beyond the throttle window — the stamp advances.
    $stale = $now->subSeconds(TouchCredentialLastUsed::THROTTLE_SECONDS + 60);
    $issued->credential->forceFill(['last_used_at' => $stale])->save();

    app('auth')->forgetGuards();
    $this->getJson('/credential-test/ping')->assertOk();

    expect($issued->credential->refresh()->last_used_at?->toIso8601String())
        ->toBe('2026-08-14T12:00:00+00:00');
});

it('ignores tokens that have no credential row', function () {
    // A merchant-user panel token (not vendor-issued) authenticates fine and
    // simply matches no api_credentials row — the listener writes nothing.
    $merchant = Merchant::factory()->create();
    $user = MerchantUser::factory()->for($merchant)->create();
    $token = $user->createToken('panel', ['*']);

    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->getJson('/credential-test/ping')
        ->assertOk();

    expect(ApiCredential::query()->count())->toBe(0);
});
