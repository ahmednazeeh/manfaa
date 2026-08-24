<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Settlement\WalletAutoSettler;
use Illuminate\Console\Command;

/**
 * The hourly wallet run (owner, 2026-08-24): ten minutes after the
 * validation sweep has put the hour's sales on the settlement clock, spend
 * every willing merchant's wallet balance on their oldest validated
 * cashback, as far as it reaches. All of the thinking is in
 * WalletAutoSettler; this is the clock hand.
 */
final class AutoSettleWalletsCommand extends Command
{
    protected $signature = 'manfaa:auto-settle-wallets';

    protected $description = 'Settle validated cashback from each opted-in merchant\'s wallet balance, oldest first, as far as it reaches';

    public function handle(WalletAutoSettler $settler): int
    {
        $run = $settler->run();

        $this->info(sprintf(
            '%d merchant(s) checked, %d settled from wallet, %d skipped.',
            $run['checked'],
            $run['settled'],
            $run['skipped'],
        ));

        return self::SUCCESS;
    }
}
