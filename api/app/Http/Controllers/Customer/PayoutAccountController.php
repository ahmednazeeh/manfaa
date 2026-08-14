<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payout account registration (§10 apps/web).
 *
 * Snapshot semantics (existing since Phase 1): payout_items copy bank /
 * account / account_name from the customer row at BATCH BUILD time. Changing
 * the account here while a payout batch is processing is therefore allowed
 * and safe — the in-flight batch keeps paying the snapshotted account, and
 * the change takes effect from the next batch build.
 */
class PayoutAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return $this->payload($customer);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'account_no' => ['required', 'string', 'max:32', 'regex:/^\d{6,32}$/'],
            'account_name' => ['required', 'string', 'max:120'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');

        $customer->forceFill([
            'payout_bank' => $validated['bank_name'],
            'payout_account' => $validated['account_no'],
            'payout_account_name' => $validated['account_name'],
        ])->save();

        return $this->payload($customer);
    }

    private function payload(Customer $customer): JsonResponse
    {
        $has = filled($customer->payout_bank)
            && filled($customer->payout_account)
            && filled($customer->payout_account_name);

        return response()->json([
            'data' => [
                'bank_name' => $customer->payout_bank,
                'account_no' => $customer->payout_account,
                'account_name' => $customer->payout_account_name,
                'has_payout_account' => $has,
                // Key, not prose (frontend translates): a change during a
                // processing batch applies from the next batch.
                'change_effective' => 'next_batch',
            ],
        ]);
    }
}
