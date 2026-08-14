<?php

namespace App\Console\Commands;

use App\Domain\Standing\SuspensionService;
use Illuminate\Console\Command;

class SuspendOverdueCommand extends Command
{
    protected $signature = 'manfaa:suspend-overdue';

    protected $description = 'Automatically suspend merchants with unfunded payables past due (§7 day 16 — the only credit control)';

    public function handle(SuspensionService $suspensions): int
    {
        $suspended = $suspensions->suspendOverdue();

        $this->info(sprintf('%d merchant(s) suspended.', $suspended));

        return self::SUCCESS;
    }
}
