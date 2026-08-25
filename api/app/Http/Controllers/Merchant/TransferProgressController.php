<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Transfers\TransferProgress;
use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use App\Models\WalletTopUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two reads a merchant screen polls while Manfaa watches the bank
 * (owner, 2026-08-25).
 *
 * A merchant uploads a transfer slip — for a settlement or for a wallet
 * top-up — and the server begins polling the bank's own history behind
 * them. These endpoints are how the screen SEES that: whether a watch is
 * genuinely running, how long it has left, how many times the bank has been
 * asked, and then the real outcome in the same place. The server's work does
 * not depend on anyone looking; this is a window onto it, not a driver of
 * it.
 *
 * DESIGNED TO BE POLLED. Both actions are read-only, touch three or four
 * indexed rows, load no relations and render no collection. The response is
 * the shared shape built by {@see TransferProgress} — identical for both
 * flows so the two screens share one parser.
 *
 * SCOPED BY OWNERSHIP, NOT BY CHECK. Every lookup is filtered on the
 * authenticated merchant's own id, so another store's settlement or top-up
 * is indistinguishable from one that does not exist — a 404, never a 403,
 * because a 403 would confirm the row is real.
 */
class TransferProgressController extends Controller
{
    /**
     * The newest bank payment recorded against one of the merchant's own
     * batches — the slip they just uploaded, or the last one they did.
     *
     * A batch with no bank payment at all (settled from the wallet, or
     * built by an admin and not yet paid) has no transfer to report on and
     * answers 404, exactly as a batch belonging to somebody else does.
     */
    public function settlement(Request $request, int $id, TransferProgress $progress): JsonResponse
    {
        $merchantId = $this->merchantUser($request)->merchant_id;

        // THE PAYMENT IS READ FIRST, ON PURPOSE. The two rows are decided
        // together under one lock (SettlementAllocator::matchPayment), but
        // they are read here in two statements — and a match committing
        // between them must not be able to pair a decided payment with a
        // batch that has not learnt of it yet. In this order the only
        // possible skew is a payment that still says `pending`, which
        // reports no outcome at all: the screen keeps polling and the next
        // read tells the truth. The other order would freeze a wrong
        // "partially settled, you still owe the lot" on a batch that is in
        // fact paid off, and both clients stop polling on any outcome.
        //
        // It also carries the OWNERSHIP check on its own: every payment row
        // stores the merchant it belongs to, so another store's batch finds
        // no payment here and 404s exactly as a batch with no bank payment
        // does — indistinguishable, which is the point.
        /** @var SettlementPayment|null $payment */
        $payment = SettlementPayment::query()
            ->where('settlement_id', $id)
            ->where('merchant_id', $merchantId)
            ->select([
                'id',
                'settlement_id',
                // The claim AND what the bank actually credited: the
                // payload carries both, so both have to be selected.
                'amount_laari',
                'received_laari',
                'state',
                'auto_matched',
                'poll_started_at',
                'poll_until',
                'poll_attempts',
                'matched_at',
                'rejected_at',
                'rejection_reason',
            ])
            // The NEWEST: on a partially settled batch the merchant is
            // watching the receipt they just sent, not the one that landed
            // last week.
            ->orderByDesc('id')
            ->first();

        if ($payment === null) {
            abort(404);
        }

        /** @var Settlement $settlement */
        $settlement = Settlement::query()
            ->where('merchant_id', $merchantId)
            // Only the columns the payload reads. A progress poll must not
            // drag a batch's whole row across every five seconds; the lines
            // are touched by one SUM, never loaded.
            ->select([
                'id',
                'merchant_id',
                'reference',
                'state',
                'amount_due_laari',
                'amount_received_laari',
                'discount_laari',
                'discount_posted_laari',
                'platform_bank_account_id',
            ])
            ->findOrFail($id);

        return new JsonResponse(['data' => $progress->forSettlementPayment($settlement, $payment)]);
    }

    /**
     * One of the merchant's own wallet top-up claims.
     */
    public function walletTopUp(Request $request, int $id, TransferProgress $progress): JsonResponse
    {
        /** @var WalletTopUp $topUp */
        $topUp = WalletTopUp::query()
            ->where('merchant_id', $this->merchantUser($request)->merchant_id)
            ->select([
                'id',
                'merchant_id',
                // The claim AND what the bank actually credited.
                'amount_laari',
                'received_laari',
                'state',
                'platform_bank_account_id',
                'auto_matched',
                'poll_started_at',
                'poll_until',
                'poll_attempts',
                'matched_at',
                'rejected_at',
                'rejected_reason',
            ])
            ->findOrFail($id);

        return new JsonResponse(['data' => $progress->forWalletTopUp($topUp)]);
    }

    private function merchantUser(Request $request): MerchantUser
    {
        /** @var MerchantUser */
        return $request->user('merchant');
    }
}
