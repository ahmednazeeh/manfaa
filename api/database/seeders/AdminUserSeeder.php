<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Outside local/testing a password MUST be supplied explicitly — under
     * `config:cache` env() reads the real process environment, so a missing
     * SEED_ADMIN_PASSWORD fails loudly instead of seeding 'password'.
     * firstOrCreate never resets a rotated password on re-run.
     */
    public function run(): void
    {
        $password = env('SEED_ADMIN_PASSWORD');

        if ($password === null && ! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'Refusing to seed the superadmin without SEED_ADMIN_PASSWORD outside local/testing.'
            );
        }

        AdminUser::query()->firstOrCreate(
            ['email' => 'admin@manfaa.app'],
            [
                'name' => 'Manfaa Admin',
                'password' => $password ?? 'password',
                'role' => 'superadmin',
            ],
        );
    }
}
