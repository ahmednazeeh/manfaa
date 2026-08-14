<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Merchant;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('refuses to seed the superadmin outside local/testing without SEED_ADMIN_PASSWORD', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new AdminUserSeeder)->run())->toThrow(RuntimeException::class);
    expect(AdminUser::query()->count())->toBe(0);
});

it('seeds the superadmin in testing and never resets a rotated password on re-run', function () {
    (new AdminUserSeeder)->run();

    $admin = AdminUser::query()->sole();
    $admin->update(['password' => 'rotated-by-the-operator']);
    $rotatedHash = $admin->refresh()->password;

    (new AdminUserSeeder)->run();

    expect(AdminUser::query()->count())->toBe(1)
        ->and($admin->refresh()->password)->toBe($rotatedHash);
});

it('seeds the demo fixtures in testing but never outside local/testing', function () {
    $this->app['env'] = 'production';
    (new DemoSeeder)->run();

    expect(Merchant::query()->count())->toBe(0);

    $this->app['env'] = 'testing';
    (new DemoSeeder)->run();

    expect(Merchant::query()->where('slug', 'demo-store')->exists())->toBeTrue();
});
