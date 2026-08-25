<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Cashback\TransactionState;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\SettlementState;
use App\Models\MerchantChangeRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WHAT IS WAITING ON A HUMAN — the six queues an admin opens the console to
 * clear, counted in ONE round trip.
 *
 * Every count is the queue's OWN predicate, taken from the endpoint that
 * serves it, so a badge can never say 3 while the list shows 4:
 *
 *   settlements_payment_review   settlements.state = 'payment_review'
 *                                (Admin\SettlementController::index?state=,
 *                                which is what /settlements?state=payment_review
 *                                lists — BATCHES, not receipts)
 *   wallet_top_ups_pending       wallet_top_ups.state = 'pending'
 *                                (Admin\WalletTopUpController::index)
 *   store_reviews_pending        merchants.status = 'pending_review'
 *                                (Admin\StoreReviewController::index)
 *   change_requests_pending      merchant_change_requests.status = 'pending'
 *                                (Admin\ChangeRequestController::index)
 *   holds_open                   transactions.state = 'on_hold'
 *                                (HoldReviewService::baseQuery — the lateral
 *                                join there decorates the row, it does not
 *                                narrow the set)
 *   marketplace_kyb_pending      merchant_marketplace_profiles.state =
 *                                'pending_kyb' (Admin\MarketplaceKybController)
 *
 * ALL ADMINS see these. They are counts of work, not money: the numbers say
 * how many things are waiting, never what they are worth, and every one of
 * the six lists behind them is already open to any admin (the SUPERADMIN
 * gate on several of those queues is on the decision — approve, reject —
 * never on the reading).
 *
 * THE SETTLEMENT QUEUE COUNTS BATCHES, NOT RECEIPTS, and that is the rule
 * above being obeyed rather than an approximation of it. One batch can carry
 * several simultaneously-pending receipts — SettlementAllocator::storeBankPayment
 * accepts a new pending payment on a batch that is already in payment_review,
 * which is exactly what SettlementBuilder::addReceipt exists to do — so
 * counting `settlement_payments.state = 'pending'` made the tile say 2 where
 * the screen it opens showed 1 row. The list is `Settlement::where('state', …)`,
 * so the count is too. Nothing is lost: a batch is put INTO payment_review by
 * the first pending payment on it, leaves it by matchPayment (partially_settled
 * or settled) and by reject (cancelled, every pending payment rejected with it),
 * so "in payment_review" and "has a receipt waiting" are the same set.
 *
 * MARKETPLACE IS CONDITIONAL. With the flag off, the key is absent rather
 * than zero: PLAN-marketplace.md §10 says "off means every surface hides
 * it", and a permanent "0 KYB applications" tile is exactly the surface it
 * means. `total` then adds up the queues that exist.
 *
 * ONE QUERY, six scalar subselects. Six separate counts would be six round
 * trips on a page that loads for every admin at every login, and each
 * subselect is independently planned against its own index, so the shape
 * costs nothing but the saved latency.
 */
final class AttentionQueues
{
    public function __construct(private readonly PlatformConfig $config) {}

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $marketplace = $this->config->marketplaceEnabled();

        $query = DB::query()
            ->selectSub($this->tally(DB::table('settlements')->where('state', SettlementState::PaymentReview->value)), 'settlements_payment_review')
            ->selectSub($this->tally(DB::table('wallet_top_ups')->where('state', 'pending')), 'wallet_top_ups_pending')
            ->selectSub($this->tally(DB::table('merchants')->where('status', 'pending_review')), 'store_reviews_pending')
            ->selectSub($this->tally(DB::table('merchant_change_requests')->where('status', MerchantChangeRequest::PENDING)), 'change_requests_pending')
            ->selectSub($this->tally(DB::table('transactions')->where('state', TransactionState::OnHold->value)), 'holds_open');

        if ($marketplace) {
            $query->selectSub(
                $this->tally(DB::table('merchant_marketplace_profiles')->where('state', 'pending_kyb')),
                'marketplace_kyb_pending',
            );
        }

        $row = (array) $query->first();

        $counts = [];

        foreach ($row as $key => $value) {
            $counts[(string) $key] = (int) $value;
        }

        return [...$counts, 'total' => array_sum($counts)];
    }

    private function tally(Builder $query): Builder
    {
        return $query->selectRaw('COUNT(*)');
    }
}
