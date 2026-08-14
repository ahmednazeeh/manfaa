<?php

namespace App\Console\Commands;

use App\Domain\Standing\ValidationSweeper;
use Illuminate\Console\Command;

class SweepValidationCommand extends Command
{
    protected $signature = 'manfaa:sweep-validation';

    protected $description = 'Move transactions whose validation window has elapsed onto the 15-day settlement clock (§7 day 0)';

    public function handle(ValidationSweeper $sweeper): int
    {
        $swept = $sweeper->run();

        $this->info(sprintf('%d transaction(s) moved to payable_unfunded.', $swept));

        return self::SUCCESS;
    }
}
