<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\Money\Percent;
use App\Domain\Platform\PlatformConfig;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Suborder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cart → order (PLAN-marketplace.md §2.6, `Payment Step.png`).
 *
 * Two things happen here that happen nowhere else.
 *
 * **Every rate is FROZEN.** The cashback rate, the platform's fee, the
 * delivery terms and every price are copied onto the order at the moment the
 * customer agrees to them. A fee change next week, a merchant repricing a
 * shelf, a delivery rule edited overnight — none of them may reach backwards
 * into an order already placed. This is also what makes MP6's amendments
 * safe to recompute: the arithmetic is redone against the frozen rates, not
 * against today's.
 *
 * **The cart empties only on success.** A checkout that failed halfway and
 * took the basket with it is the worst outcome available, so the whole thing
 * is one transaction.
 */
final readonly class CheckoutService
{
    public function __construct(
        private CartPricer $pricer,
        private PlatformConfig $config,
    ) {}

    public function place(
        Customer $customer,
        Cart $cart,
        ?CustomerAddress $address,
        string $paymentMethod,
    ): Order {
        $priced = $this->pricer->price($cart, $address);

        $this->assertPlaceable($priced, $address);

        return DB::transaction(function () use ($customer, $cart, $address, $paymentMethod, $priced): Order {
            $now = CarbonImmutable::now();

            $order = Order::query()->create([
                'reference' => $this->nextOrderReference(),
                'customer_id' => $customer->getKey(),
                'address_id' => $address?->getKey(),
                // The address AS IT WAS. Edited or deleted later, it must not
                // rewrite where this order went.
                'address_snapshot' => $address === null ? null : [
                    'label' => $address->label,
                    'recipient_name' => $address->recipient_name,
                    'phone' => $address->phone,
                    'building' => $address->building,
                    'island' => $address->island,
                    'area_magu' => $address->area_magu,
                    'apartment_floor' => $address->apartment_floor,
                    'delivery_note' => $address->delivery_note,
                    'lat' => $address->lat,
                    'lng' => $address->lng,
                    'zone_id' => $address->zone_id,
                ],
                'items_laari' => $priced['items_laari'],
                'delivery_laari' => $priced['delivery_laari'],
                'total_payable_laari' => $priced['total_payable_laari'],
                'cashback_total_laari' => $priced['cashback_laari'],
                'payment_method' => $paymentMethod,
                'payment_state' => 'awaiting_proof',
                'state' => 'placed',
                'placed_at' => $now,
            ]);

            foreach (array_values($priced['subcarts']) as $index => $subcart) {
                $this->createSuborder($order, $subcart, $index + 1);
            }

            // Only now. A failure above rolls the basket back intact.
            $cart->items()->delete();

            return $order;
        })->refresh();
    }

    /**
     * @param  array<string, mixed>  $priced
     */
    private function assertPlaceable(array $priced, ?CustomerAddress $address): void
    {
        if ($priced['subcarts'] === []) {
            throw CheckoutException::empty();
        }

        foreach ($priced['subcarts'] as $subcart) {
            $store = sprintf('%s — %s', $subcart['store_name'], $subcart['branch_name']);

            if (! $subcart['all_available']) {
                throw CheckoutException::unavailable($store);
            }

            if (! $subcart['delivery']['minimum_met']) {
                throw CheckoutException::belowMinimum($store, (int) $subcart['delivery']['shortfall_laari']);
            }

            // A shop that cannot deliver to this address may still be
            // collected from — but only if the customer is buying for
            // pickup, and that needs an address decision either way.
            if (! $subcart['delivery']['delivers'] && $address === null) {
                throw CheckoutException::needsAddress();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $subcart
     */
    private function createSuborder(Order $order, array $subcart, int $position): void
    {
        $merchant = Merchant::query()->findOrFail($subcart['merchant_id']);

        // The fee this store is on: its own override, or the platform's
        // default. Frozen onto the row either way.
        $feeBp = $merchant->marketplace?->order_fee_bp ?? $this->config->marketplaceFeeBp();

        $items = (int) $subcart['items_laari'];
        $delivery = (int) $subcart['delivery']['fee_laari'];
        $cashback = (int) $subcart['cashback_laari'];

        // Items only, never delivery (§5.1) — a merchant recovering a
        // courier's cost should not be charged for the privilege.
        $fee = intdiv($items * $feeBp + 9999, 10000);
        // The column exists and the arithmetic is wired; the platform's GST
        // treatment is currently zero everywhere (CreditRecorder does the
        // same), so this stays 0 until that decision is made.
        $gst = 0;

        $suborder = Suborder::query()->create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'branch_id' => $subcart['branch_id'],
            'reference' => $this->suborderReference($order->reference, $position),
            'fulfilment' => $subcart['delivery']['delivers'] ? 'delivery' : 'pickup',
            'items_laari' => $items,
            'delivery_laari' => $delivery,
            'subtotal_laari' => $items + $delivery,
            'cashback_rate_bp' => Percent::toBasisPoints($subcart['cashback_rate_percent']),
            'cashback_laari' => $cashback,
            'order_fee_bp' => $feeBp,
            'order_fee_laari' => $fee,
            'order_fee_gst_laari' => $gst,
            'payable_to_merchant_laari' => $items + $delivery - $cashback - $fee - $gst,
            'state' => 'new',
        ]);

        foreach ($subcart['items'] as $line) {
            $suborder->items()->create([
                'product_id' => $line['product_id'],
                // The NAME as it was. A renamed product must not rewrite
                // what somebody ordered last month.
                'name' => $line['name'],
                'unit_price_laari' => $line['unit_price_laari'],
                'qty' => $line['qty'],
                // Nothing amended yet: what was ordered is what is expected.
                'fulfilled_qty' => $line['qty'],
                'line_total_laari' => $line['line_total_laari'],
                'cashback_laari' => $line['cashback_laari'],
            ]);
        }
    }

    /**
     * MF-<year>-<seq>, the reference in `Order Received.png`.
     *
     * Serialised by an advisory lock scoped to the surrounding transaction,
     * exactly as settlement references are; the unique index backstops it.
     */
    private function nextOrderReference(): string
    {
        $year = CarbonImmutable::now((string) config('app.business_timezone', 'Indian/Maldives'))->year;

        DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('orders-reference-'.$year)]);

        $prefix = sprintf('MF-%d-', $year);
        $latest = Order::query()->where('reference', 'like', $prefix.'%')->max('reference');
        $next = $latest === null ? 1 : (int) substr((string) $latest, strlen($prefix)) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** MF-1084-01 — the order's own number, then the shop's position in it. */
    private function suborderReference(string $orderReference, int $position): string
    {
        $tail = substr($orderReference, strrpos($orderReference, '-') + 1);

        return sprintf('MF-%s-%02d', $tail, $position);
    }
}
