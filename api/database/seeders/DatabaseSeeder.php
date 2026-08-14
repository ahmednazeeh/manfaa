<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Chart of accounts first — nothing can post to the ledger without it —
     * then the admin user and the demo fixtures. All three are idempotent.
     */
    public function run(): void
    {
        $this->call([
            LedgerAccountSeeder::class,
            AdminUserSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
