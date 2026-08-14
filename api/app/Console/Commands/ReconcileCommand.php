<?php

namespace App\Console\Commands;

use App\Domain\Standing\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileCommand extends Command
{
    protected $signature = 'manfaa:reconcile';

    protected $description = 'Recompute the §5 ledger invariant and derived-vs-ledger balances, recording one reconciliation_runs row';

    public function handle(Reconciler $reconciler): int
    {
        $run = $reconciler->run();

        if ($run->status === 'divergent') {
            Log::error('Daily reconciliation diverged.', [
                'reconciliation_run_id' => $run->id,
                'issues' => $run->issues,
                'totals' => $run->totals,
            ]);

            $this->error(sprintf(
                'Reconciliation DIVERGENT: %d issue(s) across %d journal(s) — see reconciliation_runs #%d.',
                count($run->issues ?? []),
                $run->journals_checked,
                $run->id,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('Reconciliation ok: %d journal(s) checked, run #%d.', $run->journals_checked, $run->id));

        return self::SUCCESS;
    }
}
