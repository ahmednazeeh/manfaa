<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * THE ADMIN LANDING, IN ONE CALL (owner, 2026-08-25).
 *
 * One endpoint, one request, one payload — so a console dashboard never
 * fans out into eight parallel fetches whose answers arrive from eight
 * different instants and disagree with each other about a settlement that
 * matched while they were in flight.
 *
 * FOUR PANELS, TWO AUDIENCES:
 *
 *   attention, auto_match, growth   every admin. Work waiting, whether the
 *                                   bank matcher is alive, who joined.
 *   money, series                   SUPERADMIN ONLY, the same gate the
 *                                   Reports page wears, and for the same
 *                                   reason: these cross every merchant and
 *                                   every customer at once. The keys are
 *                                   ABSENT for a plain admin, never zeroed —
 *                                   a zero is an answer, and the honest thing
 *                                   is to say nothing. `can_view_money` tells
 *                                   the panel which of the two payloads it is
 *                                   holding so it can lay out the page
 *                                   without probing for keys.
 *
 * The series is gated WITH the money and not on its own: a daily chart of
 * cashback, collections and payouts is the money panel with more resolution,
 * and shipping it to an audience the totals are withheld from would hand
 * over the same figures a summation away.
 */
final class AdminDashboard
{
    public function __construct(
        private readonly AttentionQueues $attention,
        private readonly AutoMatchHealth $autoMatch,
        private readonly GrowthCounts $growth,
        private readonly MoneySnapshot $money,
        private readonly DailySeries $series,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(ReportPeriod $period, bool $includeMoney): array
    {
        $payload = [
            'period' => $period->toArray(),
            // The server's own clock, so a panel showing "as of" does not
            // print a handset's idea of now.
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'can_view_money' => $includeMoney,
            'attention' => $this->attention->counts(),
            'auto_match' => $this->autoMatch->forPeriod($period),
            'growth' => $this->growth->forPeriod($period),
        ];

        if (! $includeMoney) {
            return $payload;
        }

        // The window immediately before this one, of equal length — the only
        // comparison that makes a month-to-date figure mean anything.
        $previous = DashboardPeriod::preceding($period);

        return [
            ...$payload,
            'money' => [
                ...$this->money->forPeriod($period),
                'previous' => [
                    'period' => $previous->toArray(),
                    ...$this->money->forPeriod($previous),
                ],
            ],
            'series' => $this->series->forPeriod($period),
        ];
    }
}
