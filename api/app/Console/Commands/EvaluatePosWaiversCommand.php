<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PosWaiver\PosWaiverEvaluator;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The monthly POS-waiver run (owner, 2026-08-23). Scheduled for the 3rd,
 * business time, so refunds from the month's last days have landed before
 * the month is judged. Evaluates EVERY approved merchant — the /v1
 * endpoints filter to a platform's own connections when asked.
 */
final class EvaluatePosWaiversCommand extends Command
{
    protected $signature = 'manfaa:evaluate-pos-waivers {--month= : YYYY-MM, defaults to last month}';

    protected $description = 'Evaluate the POS fee waiver for every merchant for a closed month';

    public function handle(PosWaiverEvaluator $evaluator): int
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $month = $this->option('month') !== null
            ? CarbonImmutable::createFromFormat('Y-m', (string) $this->option('month'), $timezone)->startOfMonth()
            : CarbonImmutable::now($timezone)->subMonthNoOverflow()->startOfMonth();

        if (CarbonImmutable::now($timezone)->startOfMonth()->lessThanOrEqualTo($month)) {
            $this->error('Only a CLOSED month can be evaluated.');

            return self::FAILURE;
        }

        $qualified = 0;
        $total = 0;

        Merchant::query()
            ->whereNotNull('approved_at')
            ->orderBy('id')
            ->chunkById(100, function ($merchants) use ($evaluator, $month, &$qualified, &$total): void {
                foreach ($merchants as $merchant) {
                    $row = $evaluator->evaluate($merchant, $month);
                    $total++;
                    $qualified += $row->qualified ? 1 : 0;
                }
            });

        $this->info(sprintf('%s: %d merchants evaluated, %d qualified.', $month->format('Y-m'), $total, $qualified));

        return self::SUCCESS;
    }
}
