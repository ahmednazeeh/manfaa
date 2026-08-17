<?php

namespace App\Console\Commands;

use App\Domain\Standing\SettlementReminders;
use Illuminate\Console\Command;

class RemindSettlementsCommand extends Command
{
    protected $signature = 'manfaa:remind-settlements';

    protected $description = 'Send the settlement deadline reminders — prompt-discount expiring (last earnable day) and due-soon (the two days before overdue) — to staff holding settlements.view';

    public function handle(SettlementReminders $reminders): int
    {
        $sent = $reminders->run();

        $this->info(sprintf('%d settlement reminder(s) sent.', $sent));

        return self::SUCCESS;
    }
}
