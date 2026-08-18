<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Platform\PlatformConfig;
use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayout;
use App\Models\CustomerWalletEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's wallet: what is in it, where it came from, and getting it
 * out (owner decision 2026-08-19).
 *
 * Refunds arrive here instantly. The balance is ALWAYS withdrawable — money
 * that can get trapped in a wallet is money somebody will rightly argue
 * with us about.
 */
final class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    public function show(Request $request, PlatformConfig $config): JsonResponse
    {
        $customer = $this->customer($request);
        $wallet = $this->wallet->walletFor($customer);

        return new JsonResponse(['data' => [
            'balance_laari' => $wallet->balance_laari,
            'currency' => $wallet->currency,
            // Bank transfers cost something, so small withdrawals wait until
            // they are worth sending. Same figure the cashback payouts use.
            'minimum_withdrawal_laari' => $config->minPayoutLaari(),
            'can_withdraw' => $wallet->balance_laari >= $config->minPayoutLaari()
                && trim((string) $customer->payout_account) !== '',
            'has_bank_account' => trim((string) $customer->payout_account) !== '',
            'entries' => $wallet->entries()
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (CustomerWalletEntry $entry): array => [
                    'id' => $entry->id,
                    'amount_laari' => $entry->amount_laari,
                    'balance_after_laari' => $entry->balance_after_laari,
                    'type' => $entry->type,
                    'description' => $entry->description,
                    'at' => $entry->created_at?->toIso8601String(),
                ])->values(),
            'withdrawals' => CustomerPayout::query()
                ->where('customer_id', $customer->getKey())
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (CustomerPayout $payout): array => [
                    'id' => $payout->id,
                    'amount_laari' => $payout->amount_laari,
                    'state' => $payout->state,
                    'requested_at' => $payout->requested_at?->toIso8601String(),
                    // Deliberately not the approval id — a customer reading
                    // a queue record id as a bank reference would quote it
                    // at their bank and get nowhere.
                    'bank_reference' => $payout->trx_id,
                ])->values(),
        ]]);
    }

    public function withdraw(Request $request, PlatformConfig $config): JsonResponse
    {
        $validated = $request->validate([
            'amount_laari' => ['required', 'integer', 'min:1'],
        ]);

        $customer = $this->customer($request);
        $minimum = $config->minPayoutLaari();

        if ($validated['amount_laari'] < $minimum) {
            return new JsonResponse([
                'message' => sprintf('The smallest withdrawal is MVR %s.', number_format($minimum / 100, 2)),
                'code' => 'below_minimum',
            ], 422);
        }

        try {
            $payout = $this->wallet->requestWithdrawal($customer, (int) $validated['amount_laari']);
        } catch (WalletException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        return new JsonResponse(['data' => [
            'id' => $payout->id,
            'amount_laari' => $payout->amount_laari,
            'state' => $payout->state,
            // The balance already reflects it: the money is committed the
            // moment they ask, not when the bank finally moves it.
            'balance_laari' => $this->wallet->walletFor($customer)->balance_laari,
        ]], 201);
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }
}
