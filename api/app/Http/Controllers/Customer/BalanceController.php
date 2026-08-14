<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\BalanceQuery;
use App\Domain\Customers\PayoutWindow;
use App\Domain\Payout\EligibilityQuery;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The balance screen (§10 apps/web): Confirmed is the HEADLINE and is never
 * summed with pending — pending is separate, conditional money. Nothing is
 * promised before Confirmed (§1).
 */
class BalanceController extends Controller
{
    public function show(Request $request, BalanceQuery $balances): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $now = CarbonImmutable::now('UTC');
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');

        $sums = $balances->balances($customer, $now, $timezone);

        return response()->json([
            'data' => [
                'currency' => 'MVR',
                // The headline figure. NEVER add pending_laari to it.
                'confirmed_laari' => $sums['confirmed_laari'],
                // Conditional money, shown separately and always as conditional.
                'pending_laari' => $sums['pending_laari'],
                'paid_this_month_laari' => $sums['paid_this_month_laari'],
                'minimum_payout_laari' => EligibilityQuery::MINIMUM_PAYOUT_LAARI,
                'next_payout_window' => PayoutWindow::next($now, $timezone),
                'has_payout_account' => filled($customer->payout_bank)
                    && filled($customer->payout_account)
                    && filled($customer->payout_account_name),
            ],
        ]);
    }
}
