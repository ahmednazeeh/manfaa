<?php

namespace App\Console\Commands;

use App\Domain\Standing\WriteOffService;
use Illuminate\Console\Command;

class WriteOffCommand extends Command
{
    protected $signature = 'manfaa:write-off';

    protected $description = 'Write off unfunded payables more than 90 days past due (§7 +90) with the §8 write-off posting';

    public function handle(WriteOffService $writeOffs): int
    {
        $writtenOff = $writeOffs->run();

        $this->info(sprintf('%d transaction(s) written off.', $writtenOff));

        return self::SUCCESS;
    }
}
