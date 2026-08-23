<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\MaskedName;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's referral page (owner, 2026-08-23): their code — which is
 * simply their customer_code — the programme's live numbers, and how each
 * invited friend is progressing. One controller, both doors: mounted on the
 * web customer routes and the mobile tree, exactly like the wallet.
 *
 * PRIVACY: a referrer never learns more about a friend than the friend's
 * own signup gave away. The name is MASKED, spend is CAPPED at the
 * threshold ("how far along the bar" — never "how much they really spend"),
 * and no merchant, transaction or per-purchase detail ever appears here.
 */
final class ReferralsController extends Controller
{
    public function show(
        Request $request,
        PlatformConfig $config,
        WalletService $wallet,
    ): JsonResponse {
        $customer = $this->customer($request);
        $threshold = $config->referralSpendThresholdLaari();

        $friends = Customer::query()
            ->where('referred_by_customer_id', $customer->getKey())
            ->orderByDesc('id')
            ->get();

        // One grouped SUM for every friend still mid-bar, instead of a
        // query per row. Already-rewarded friends need no SUM at all —
        // their bar is full by definition.
        $pendingIds = $friends->whereNull('referral_rewarded_at')->pluck('id');
        $spend = $pendingIds->isEmpty() ? collect() : Transaction::query()
            ->whereIn('customer_id', $pendingIds)
            ->whereIn('state', ['payable_unfunded', 'confirmed', 'paid'])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(eligible_laari) AS spent_laari')
            ->pluck('spent_laari', 'customer_id');

        // What the programme has actually paid this customer, read from
        // their own wallet ledger — honest across any reward-figure change.
        $earnedTotal = (int) $wallet->walletFor($customer)
            ->entries()
            ->where('type', 'referral')
            ->sum('amount_laari');

        return new JsonResponse(['data' => [
            'enabled' => $config->referralEnabled(),
            'reward_laari' => $config->referralRewardLaari(),
            'threshold_laari' => $threshold,
            'code' => $customer->customer_code,
            'share_url' => 'https://manfaa.app/signup?ref='.$customer->customer_code,
            'stats' => [
                'invited' => $friends->count(),
                'rewarded' => $friends->whereNotNull('referral_rewarded_at')->count(),
                'earned_total_laari' => $earnedTotal,
            ],
            'friends' => $friends->map(function (Customer $friend) use ($spend, $threshold): array {
                $rewarded = $friend->referral_rewarded_at !== null;

                return [
                    'name' => MaskedName::of((string) $friend->name),
                    'joined_at' => ($friend->referred_at ?? $friend->created_at)?->toIso8601String(),
                    // Capped at the threshold: progress toward the bonus,
                    // never a window onto the friend's real spending.
                    'spent_laari' => $rewarded
                        ? $threshold
                        : min((int) ($spend[$friend->getKey()] ?? 0), $threshold),
                    'rewarded' => $rewarded,
                ];
            })->values(),
        ]]);
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }
}
