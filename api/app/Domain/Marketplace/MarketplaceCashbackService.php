<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransitionService;
use App\Models\Suborder;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A delivered marketplace order becomes cashback (§5.3).
 *
 * One wallet, one Activity feed, one payout — so this writes an ordinary
 * `transactions` row and hands it to the ordinary machinery. What it does
 * NOT do is make the merchant owe us anything: we are already holding the
 * customer's money and deducted the cashback before paying the shop, so the
 * row carries a zero fee and SettlementBuilder excludes its origin.
 *
 * Idempotent by the unique index on `suborder_id`, not by a flag: a retried
 * job, a double-tapped Delivered button and a replayed queue message all
 * lose at the database rather than paying somebody twice.
 */
final readonly class MarketplaceCashbackService
{
    public function __construct(private TransitionService $transitions) {}

    /**
     * Credit what this shop actually supplied.
     *
     * Returns null when there is nothing to credit — a suborder already
     * credited, one that earned nothing, or a customer who has since been
     * removed. None of those is an error.
     */
    public function credit(Suborder $suborder): ?Transaction
    {
        if ($suborder->state !== 'delivered') {
            return null;
        }

        $customer = $suborder->order?->customer;

        if ($customer === null || $suborder->cashback_laari <= 0) {
            return null;
        }

        if (Transaction::query()->where('suborder_id', $suborder->id)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($suborder, $customer): ?Transaction {
            // Re-read under the lock: two Delivered taps racing must not
            // both pass the existence check above.
            $locked = Suborder::query()->whereKey($suborder->getKey())->lockForUpdate()->firstOrFail();

            if (Transaction::query()->where('suborder_id', $locked->id)->exists()) {
                return null;
            }

            $now = CarbonImmutable::now('UTC');

            $transaction = Transaction::query()->create([
                'merchant_id' => $locked->merchant_id,
                'branch_id' => $locked->branch_id,
                'customer_id' => $customer->id,
                'suborder_id' => $locked->id,
                'origin' => 'marketplace',
                // The shop's own reference for this order — what both sides
                // will quote at each other if anything is ever disputed.
                'invoice_no' => $locked->reference,
                'external_ref' => $locked->order?->reference,
                // What was actually supplied, after any amendment. The
                // frozen figures on the suborder already account for it.
                'eligible_laari' => $locked->items_laari,
                'sale_laari' => $locked->subtotal_laari,
                'currency' => 'MVR',
                'rate_bp' => $locked->cashback_rate_bp,
                'cashback_laari' => $locked->cashback_laari,
                // ZERO, deliberately. Our cut was the marketplace fee, taken
                // from the merchant's payout; charging the cashback fee here
                // would bill them twice for one sale.
                'fee_bp' => 0,
                'fee_laari' => 0,
                'fee_gst_laari' => 0,
                'state' => 'tracked',
                'occurred_at' => $locked->delivered_at ?? $now,
                'received_at' => $now,
            ]);

            $this->transitions->recordCreated($transaction, Actor::system());
            // The store's validation window still applies: a marketplace
            // return is as real as a till one, and the shopper is paid on
            // the same clock they already know.
            $this->transitions->startValidation($transaction, Actor::system());

            return $transaction->refresh();
        });
    }
}
