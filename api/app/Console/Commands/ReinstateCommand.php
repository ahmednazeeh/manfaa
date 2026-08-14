<?php

namespace App\Console\Commands;

use App\Domain\Standing\SuspensionService;
use Illuminate\Console\Command;

class ReinstateCommand extends Command
{
    protected $signature = 'manfaa:reinstate';

    protected $description = 'Reinstate suspended merchants with no overdue unfunded payables remaining';

    public function handle(SuspensionService $suspensions): int
    {
        $reinstated = $suspensions->reinstate();

        $this->info(sprintf('%d merchant(s) reinstated.', $reinstated));

        return self::SUCCESS;
    }
}
