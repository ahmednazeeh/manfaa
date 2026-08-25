<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Reports\ReportPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WHO JOINED — customers registered and stores trading, as a total and as
 * what the period itself added.
 *
 * Counts of people and shops, not money, so every admin sees them.
 *
 * THREE NUMBERS ABOUT STORES, not two, because "new active merchants" is
 * genuinely ambiguous and the honest answer is to say both things:
 * `active_total` is the estate trading today, `new_active_in_period` are the
 * ones that both registered in the window AND are trading now, and
 * `registered_in_period` counts every store that signed up in the window
 * whatever became of it — a signup wave that is all still sitting in the
 * approval queue is a fact the dashboard should be able to show, and the
 * difference between the last two is exactly that queue.
 *
 * One query: four scalar subselects, each over an indexed created_at.
 */
final class GrowthCounts
{
    /**
     * @return array{customers: array{total: int, new_in_period: int}, merchants: array{active_total: int, new_active_in_period: int, registered_in_period: int}}
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $row = DB::query()
            ->selectSub($this->tally(DB::table('customers')), 'customers_total')
            ->selectSub($this->tally($this->within(DB::table('customers'), $period)), 'customers_new')
            ->selectSub($this->tally(DB::table('merchants')->where('status', 'active')), 'merchants_active')
            ->selectSub($this->tally($this->within(DB::table('merchants')->where('status', 'active'), $period)), 'merchants_new_active')
            ->selectSub($this->tally($this->within(DB::table('merchants'), $period)), 'merchants_registered')
            ->first();

        return [
            'customers' => [
                'total' => (int) $row->customers_total,
                'new_in_period' => (int) $row->customers_new,
            ],
            'merchants' => [
                'active_total' => (int) $row->merchants_active,
                'new_active_in_period' => (int) $row->merchants_new_active,
                'registered_in_period' => (int) $row->merchants_registered,
            ],
        ];
    }

    /** The period's own half-open range, in business time like every other window. */
    private function within(Builder $query, ReportPeriod $period): Builder
    {
        return $query
            ->where('created_at', '>=', $period->start)
            ->where('created_at', '<', $period->end);
    }

    private function tally(Builder $query): Builder
    {
        return $query->selectRaw('COUNT(*)');
    }
}
