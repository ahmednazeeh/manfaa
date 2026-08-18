<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Models\CustomerRefund;
use App\Models\MerchantUser;
use App\Models\Suborder;
use App\Models\SuborderAmendment;
use App\Models\SuborderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A shop working its orders (`Orders.png`, `Order Details.png`) and reducing
 * one when the shelf disagrees with the basket (§2.7, §5.4c).
 */
final readonly class FulfilmentService
{
    /**
     * The only moves allowed, in order. A state machine rather than a free
     * write: "ready" before "accepted" is not a shortcut, it is a lie about
     * what happened to somebody's shopping.
     *
     * @var array<string, list<string>>
     */
    public const array TRANSITIONS = [
        'new' => ['accepted', 'rejected'],
        'accepted' => ['preparing', 'rejected'],
        'preparing' => ['ready', 'rejected'],
        // Collection waits at "ready"; delivery goes out.
        'ready' => ['out_for_delivery', 'delivered'],
        'out_for_delivery' => ['delivered'],
        'delivered' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    /** The window in which a shop may still reduce an order (§2.7). */
    public const array AMENDABLE_STATES = ['accepted', 'preparing'];

    public function accept(Suborder $suborder): Suborder
    {
        $this->assertCanMoveTo($suborder, 'accepted');

        $suborder->forceFill([
            'state' => 'accepted',
            'accepted_at' => CarbonImmutable::now(),
            // A collection order needs something to prove at the counter.
            'pickup_code' => $suborder->fulfilment === 'pickup'
                ? str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)
                : null,
        ])->save();

        return $suborder->refresh();
    }

    /**
     * Refused, with a reason. The whole subtotal is owed back — the customer
     * paid us for goods this shop will not supply.
     */
    public function reject(Suborder $suborder, string $reason): Suborder
    {
        $this->assertCanMoveTo($suborder, 'rejected');

        DB::transaction(function () use ($suborder, $reason): void {
            $suborder->forceFill([
                'state' => 'rejected',
                'reject_reason' => $reason,
                'rejected_at' => CarbonImmutable::now(),
            ])->save();

            CustomerRefund::query()->create([
                'customer_id' => $suborder->order->customer_id,
                'order_id' => $suborder->order_id,
                'suborder_id' => $suborder->id,
                // Everything they paid this shop, delivery included: they
                // are getting nothing from it.
                'amount_laari' => $suborder->subtotal_laari,
                'reason' => 'suborder_rejected',
                'state' => 'pending',
            ]);
        });

        return $suborder->refresh();
    }

    public function advance(Suborder $suborder, string $to): Suborder
    {
        $this->assertCanMoveTo($suborder, $to);

        $stamps = [
            'ready' => 'ready_at',
            'delivered' => 'delivered_at',
        ];

        $suborder->forceFill(array_filter([
            'state' => $to,
            $stamps[$to] ?? 'state' => isset($stamps[$to]) ? CarbonImmutable::now() : $to,
        ]))->save();

        return $suborder->refresh();
    }

    /**
     * Reduce what this shop will supply, and owe the difference.
     *
     * @param  array<int, int>  $fulfilled  suborder_item_id => new quantity
     */
    public function amend(
        Suborder $suborder,
        ?MerchantUser $actor,
        array $fulfilled,
        string $reason,
        ?string $note = null,
    ): Suborder {
        if (! in_array($suborder->state, self::AMENDABLE_STATES, true)) {
            throw AmendmentException::notAmendable($suborder->state);
        }

        return DB::transaction(function () use ($suborder, $actor, $fulfilled, $reason, $note): Suborder {
            $locked = Suborder::query()->whereKey($suborder->getKey())->lockForUpdate()->firstOrFail();
            $items = $locked->items()->get()->keyBy('id');

            $changes = [];
            $refundTotal = 0;

            foreach ($fulfilled as $itemId => $newQty) {
                $item = $items->get($itemId) ?? throw AmendmentException::unknownItem();
                $newQty = (int) $newQty;

                // Only ever DOWN. The customer authorised an amount and paid
                // it to us; anything upward is a new order, not an edit.
                if ($newQty > $item->fulfilled_qty) {
                    throw AmendmentException::cannotIncrease();
                }

                if ($newQty === $item->fulfilled_qty) {
                    continue;
                }

                $refund = ($item->fulfilled_qty - $newQty) * $item->unit_price_laari;
                $changes[] = [$item, $item->fulfilled_qty, $newQty, $refund];
                $refundTotal += $refund;
            }

            if ($changes === []) {
                throw AmendmentException::nothingChanged();
            }

            // Everything to zero is a REJECTION, not an amendment: the
            // customer should get the rejection notice and their delivery
            // fee back, not an order for nothing.
            $remaining = $items->sum(fn (SuborderItem $item): int => $item->fulfilled_qty)
                - array_sum(array_map(fn (array $c): int => $c[1] - $c[2], $changes));

            if ($remaining <= 0) {
                throw AmendmentException::wouldEmptyOrder();
            }

            $amendment = SuborderAmendment::query()->create([
                'suborder_id' => $locked->id,
                'merchant_user_id' => $actor?->getKey(),
                'reason' => $reason,
                'note' => $note,
                'refund_laari' => $refundTotal,
            ]);

            foreach ($changes as [$item, $before, $after, $refund]) {
                $item->forceFill(['fulfilled_qty' => $after])->save();

                $amendment->lines()->create([
                    'suborder_item_id' => $item->id,
                    'qty_before' => $before,
                    'qty_after' => $after,
                    'refund_laari' => $refund,
                ]);
            }

            $this->recompute($locked);

            CustomerRefund::query()->create([
                'customer_id' => $locked->order->customer_id,
                'order_id' => $locked->order_id,
                'suborder_id' => $locked->id,
                'amendment_id' => $amendment->id,
                'amount_laari' => $refundTotal,
                'reason' => 'amendment',
                'state' => 'pending',
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Redo the whole suborder from what will actually be supplied
     * (PLAN-marketplace.md §5.4c).
     *
     * RECOMPUTED, never patched by a delta: two ceilings do not add up to
     * the ceiling of the sum, and the money law is that the ceiling is taken
     * once on the final base. Against the FROZEN rates, so a platform fee
     * change cannot reach into an order already being picked.
     *
     * Delivery does not move. If dropping an item takes the basket back
     * under the free-delivery threshold, the customer does not suddenly owe
     * a fee — they met the terms with the order they placed, and a shortage
     * on the shop's own shelf is not a reason to charge them more.
     */
    private function recompute(Suborder $suborder): void
    {
        $items = $suborder->items()->get();

        $fulfilledItems = 0;
        $cashback = 0;

        foreach ($items as $item) {
            $lineTotal = $item->fulfilled_qty * $item->unit_price_laari;
            $fulfilledItems += $lineTotal;

            $lineCashback = intdiv($lineTotal * $suborder->cashback_rate_bp + 9999, 10000);
            $cashback += $lineCashback;

            $item->forceFill([
                'line_total_laari' => $lineTotal,
                'cashback_laari' => $lineCashback,
            ])->save();
        }

        $fee = intdiv($fulfilledItems * $suborder->order_fee_bp + 9999, 10000);
        $gst = 0;

        $suborder->forceFill([
            'items_laari' => $fulfilledItems,
            // Untouched, deliberately — see the docblock.
            'delivery_laari' => $suborder->delivery_laari,
            'subtotal_laari' => $fulfilledItems + $suborder->delivery_laari,
            'cashback_laari' => $cashback,
            'order_fee_laari' => $fee,
            'order_fee_gst_laari' => $gst,
            'payable_to_merchant_laari' => $fulfilledItems + $suborder->delivery_laari - $cashback - $fee - $gst,
        ])->save();

        // The parent order follows its children — the customer's total and
        // their projected cashback both shrink.
        $order = $suborder->order;
        $siblings = $order->suborders()->get();

        $order->forceFill([
            'items_laari' => $siblings->sum('items_laari'),
            'delivery_laari' => $siblings->sum('delivery_laari'),
            'total_payable_laari' => $siblings->sum('subtotal_laari'),
            'cashback_total_laari' => $siblings->sum('cashback_laari'),
        ])->save();
    }

    private function assertCanMoveTo(Suborder $suborder, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$suborder->state] ?? [], true)) {
            throw FulfilmentException::cannotMove($suborder->state, $to);
        }
    }
}
