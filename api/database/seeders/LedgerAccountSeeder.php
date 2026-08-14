<?php

namespace Database\Seeders;

use App\Domain\Ledger\AccountCode;
use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;

class LedgerAccountSeeder extends Seeder
{
    /**
     * Seed the eight global chart-of-accounts rows (§8). Idempotent: matches
     * on code, so re-running never duplicates or renumbers accounts.
     */
    public function run(): void
    {
        foreach (AccountCode::cases() as $code) {
            LedgerAccount::query()->updateOrCreate(
                ['code' => $code->value],
                [
                    'name' => $code->label(),
                    'type' => $code->type(),
                    'scope' => 'global',
                    'owner_id' => null,
                ],
            );
        }
    }
}
