<?php

declare(strict_types=1);

use App\Models\ApiCredential;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PosVendor;
use Database\Seeders\SandboxSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('seeds the sandbox fixtures idempotently — a second run creates no duplicates', function () {
    $this->seed(SandboxSeeder::class);
    $this->seed(SandboxSeeder::class);

    $merchant = Merchant::query()->where('slug', SandboxSeeder::MERCHANT_SLUG)->sole();

    expect(PosVendor::query()->where('name', SandboxSeeder::VENDOR_NAME)->count())->toBe(1)
        ->and($merchant->branches()->count())->toBe(1)
        ->and($merchant->rates()->count())->toBe(2)
        ->and(Customer::query()->whereIn('customer_code', ['111111', '222222', '333333'])->count())->toBe(3)
        ->and(ApiCredential::query()->where('merchant_id', $merchant->id)->count())->toBe(1)
        ->and(PersonalAccessToken::query()->where('token', hash('sha256', SandboxSeeder::TOKEN_SECRET))->count())->toBe(1);

    // The published fixture state: 200 bp current, 150 bp scheduled decrease.
    $current = $merchant->rates()->where('rate_bp', SandboxSeeder::RATE_BP)->sole();
    $pending = $merchant->rates()->where('rate_bp', SandboxSeeder::PENDING_RATE_BP)->sole();

    expect($current->effective_from->isPast())->toBeTrue()
        ->and($current->effective_to->equalTo($pending->effective_from))->toBeTrue()
        ->and($pending->effective_from->isFuture())->toBeTrue()
        ->and($pending->effective_to)->toBeNull()
        ->and(Customer::query()->where('customer_code', '333333')->sole()->status)->toBe('suspended');
});

it('refuses to seed when the app environment is production', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new SandboxSeeder)->run())->toThrow(RuntimeException::class);
    expect(Merchant::query()->count())->toBe(0)
        ->and(PosVendor::query()->count())->toBe(0);
});

it('refuses to run the manfaa:sandbox command in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('manfaa:sandbox')->assertFailed();

    expect(Merchant::query()->count())->toBe(0);
});

it('prints the plaintext token from the command, and the token works against /v1', function () {
    $this->artisan('manfaa:sandbox')->assertSuccessful();

    $token = SandboxSeeder::plainTextToken();

    expect($token)->not->toBeNull();

    // Printing again on a RE-run too — the token is deterministic, so the
    // command is useful after the first seeding, not just during it.
    $this->artisan('manfaa:sandbox')
        ->expectsOutputToContain($token)
        ->assertSuccessful();

    // The credential authenticates a real §9.2 route with its abilities and
    // sees the documented fixture state: 200 bp with a 150 bp pending decrease.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertJsonPath('rate_bp', 200)
        ->assertJsonPath('fee_bp', 75)
        ->assertJsonPath('min_eligible_laari', 5000)
        ->assertJsonPath('pending_decrease.rate_bp', 150)
        ->assertJsonPath('pending_decrease.fee_bp', 50);

    // The published customer refs behave as the guide documents.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/customers/lookup?ref=111111')
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('masked_name', 'Ais*** Moh***');

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/customers/lookup?ref=333333')
        ->assertOk()
        ->assertJsonPath('valid', false);
});
