<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Transfers\PayoutSender;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Jobs\SendOnePayoutViaApi;
use App\Models\AdminUser;
use App\Models\CustomerPayout;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pending payments — money owed to customers, waiting to leave
 * (owner requirement 2026-08-19).
 *
 * Worked by hand today because the WireGuard tunnel does not exist yet. The
 * queue is the same either way: when the tunnel appears, a worker drains
 * exactly these rows through exactly this sender, and nothing about the
 * manual version is thrown away.
 */
final class PendingPaymentsController extends Controller
{
    public function __construct(
        private readonly PayoutSender $sender,
        private readonly WalletService $wallet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $state = (string) $request->query('state', 'pending');

        $payouts = CustomerPayout::query()
            ->with('customer:id,name,phone')
            ->when($state !== 'all', fn ($query) => $query->where('state', $state))
            ->orderBy('requested_at')
            ->limit(500)
            ->get();

        return new JsonResponse([
            'data' => $payouts->map(fn (CustomerPayout $payout): array => [
                'id' => $payout->id,
                'customer_name' => $payout->customer?->name,
                'customer_phone' => $payout->customer?->phone,
                'amount_laari' => $payout->amount_laari,
                'bank' => $payout->bank,
                'account' => $payout->account,
                'account_name' => $payout->account_name,
                'internal_ref' => $payout->internal_ref,
                'state' => $payout->state,
                'attempts' => $payout->attempts,
                'trx_id' => $payout->trx_id,
                // Named for what it is. An approvals-queue record id is not
                // a bank reference and must never be read as one.
                'approval_id' => $payout->approval_id,
                'error_code' => $payout->error_code,
                'failure_reason' => $payout->failure_reason,
                'requested_at' => $payout->requested_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'counts' => CustomerPayout::query()
                    ->selectRaw('state, count(*) as total')
                    ->groupBy('state')
                    ->pluck('total', 'state'),
                'auto_transfer_enabled' => TransferSetting::current()->auto_transfer_enabled,
            ],
        ]);
    }

    /** Send one through the bank API. */
    public function send(Request $request, CustomerPayout $payout): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['sometimes', 'integer', Rule::exists('transfer_profiles', 'id')->where('active', true)],
        ]);

        if ($payout->isParked()) {
            // The single most important refusal in this file: a transfer
            // waiting on a second approver is alive, and sending it again
            // pays twice.
            return new JsonResponse([
                'message' => 'This transfer is already waiting for a second approver.',
                'code' => 'pending_approval',
            ], 409);
        }

        if (! in_array($payout->state, CustomerPayout::SENDABLE, true)) {
            return new JsonResponse([
                'message' => sprintf('A payout that is %s cannot be sent.', $payout->state),
                'code' => 'not_sendable',
            ], 409);
        }

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $profile = isset($validated['profile_id'])
            ? TransferProfile::query()->find($validated['profile_id'])
            : null;

        // Queued, not called here. A transfer can take two minutes and
        // nginx hangs up at sixty seconds, so doing this inside the request
        // handed the admin a 504 while the money was still moving — an error
        // page for a transfer that very probably succeeded.
        SendOnePayoutViaApi::dispatch(
            (int) $payout->getKey(),
            $profile?->getKey(),
            (int) $admin->getKey(),
        );

        $fresh = $payout->fresh();

        return new JsonResponse(['data' => [
            'id' => $fresh->id,
            'state' => $fresh->state,
            'trx_id' => $fresh->trx_id,
            'approval_id' => $fresh->approval_id,
            'error_code' => $fresh->error_code,
            'failure_reason' => $fresh->failure_reason,
            // The honest answer to "did it send?": not yet, and the row is
            // where the outcome will appear.
            'queued' => true,
        ]], 202);
    }

    /**
     * Record a transfer an admin made by hand, outside the API — which is
     * every transfer until the tunnel exists.
     */
    public function markSent(Request $request, CustomerPayout $payout): JsonResponse
    {
        $validated = $request->validate([
            'trx_id' => ['required', 'string', 'max:120'],
        ]);

        if ($payout->state === 'sent') {
            return new JsonResponse([
                'message' => 'This payout is already sent.',
                'code' => 'already_sent',
            ], 409);
        }

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $payout->forceFill([
            'state' => 'sent',
            'trx_id' => $validated['trx_id'],
            'processed_at' => now(),
            'processed_by' => $admin->getKey(),
        ])->save();

        return new JsonResponse(['data' => ['state' => 'sent']]);
    }

    /**
     * Give up on a payout and put the money back.
     *
     * Only from a state where we KNOW nothing left the bank. A parked
     * transfer is not one of those: it may yet be approved.
     */
    public function cancel(Request $request, CustomerPayout $payout): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if (! in_array($payout->state, ['pending', 'failed'], true)) {
            return new JsonResponse([
                'message' => 'Only a pending or failed payout can be cancelled.',
                'code' => 'not_cancellable',
            ], 409);
        }

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $payout->forceFill([
            'state' => 'cancelled',
            'failure_reason' => $validated['reason'],
            'processed_at' => now(),
            'processed_by' => $admin->getKey(),
        ])->save();

        // Back to the wallet, with the reason on the ledger.
        $this->wallet->reverse($payout, 'Withdrawal cancelled: '.$validated['reason']);

        return new JsonResponse(['data' => ['state' => 'cancelled']]);
    }
}
