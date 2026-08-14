<?php

namespace App\Console\Commands;

use App\Domain\Standing\EscalationLadder;
use Illuminate\Console\Command;

class EscalateCommand extends Command
{
    protected $signature = 'manfaa:escalate';

    protected $description = 'Send the §7 escalation ladder notices (day 10 reminder, day 13 urgent, day 15 due) to merchants with unfunded payables';

    public function handle(EscalationLadder $ladder): int
    {
        $sent = $ladder->run();

        $this->info(sprintf('%d escalation notice(s) sent.', $sent));

        return self::SUCCESS;
    }
}
