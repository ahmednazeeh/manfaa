<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\PosWaiver\PosWaiverEvaluator;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PosWaiverEvaluation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The merchant's own view of the POS-fee waiver (owner, 2026-08-23): last
 * month's verdict and this month's running progress — the numbers behind
 * the dashboard's "Free IsleBooks POS" card. Commercial-standing data
 * (it names the overdue figure), so it answers to settlements.view.
 */
final class PosWaiverController extends Controller
{
    public function show(Request $request, PosWaiverEvaluator $evaluator): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return new JsonResponse(['data' => self::payload($user->merchant, $evaluator)]);
    }

    /** @return array<string, mixed> */
    public static function payload(Merchant $merchant, PosWaiverEvaluator $evaluator): array
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $now = CarbonImmutable::now($timezone);
        $lastMonth = $now->subMonthNoOverflow()->startOfMonth();

        $last = PosWaiverEvaluation::query()
            ->where('merchant_id', $merchant->getKey())
            ->whereDate('month', $lastMonth->toDateString())
            ->first();

        // The scheduler runs on the 3rd; before it has, the card computes
        // the closed month lazily so the merchant never reads a blank.
        if ($last === null && $now->day >= 1) {
            $last = $evaluator->evaluate($merchant, $lastMonth);
        }

        $progress = $evaluator->progress($merchant, $now->startOfMonth());

        return [
            'criteria' => [
                'min_rate_bp' => PosWaiverEvaluator::MIN_RATE_BP,
                'volume_threshold_laari' => PosWaiverEvaluator::VOLUME_THRESHOLD_LAARI,
                'cashback_threshold_laari' => PosWaiverEvaluator::CASHBACK_THRESHOLD_LAARI,
            ],
            'last_month' => $last === null ? null : [
                'month' => $last->month->format('Y-m'),
                'qualified' => (bool) $last->qualified,
                'volume_laari' => (int) $last->volume_laari,
                'cashback_laari' => (int) $last->cashback_laari,
                'min_rate_bp' => (int) $last->min_rate_bp,
                'overdue_laari' => (int) $last->overdue_laari,
            ],
            'current_month' => [
                'month' => $now->format('Y-m'),
                ...$progress,
                'rate_ok' => $progress['min_rate_bp'] >= PosWaiverEvaluator::MIN_RATE_BP,
                'overdue_ok' => $progress['overdue_laari'] === 0,
            ],
        ];
    }
}
