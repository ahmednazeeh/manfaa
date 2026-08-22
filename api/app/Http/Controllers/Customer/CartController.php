<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Marketplace\CartPricer;
use App\Http\Controllers\Controller;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The multi-vendor basket (PLAN-marketplace.md §3.3).
 *
 * Every response is the WHOLE priced cart, not a diff: the floating bar, the
 * subcart cards and the totals all move together after any change, and
 * returning one authoritative shape is what stops three parts of the screen
 * disagreeing after a tap.
 */
final class CartController extends Controller
{
    public function __construct(private readonly CartPricer $pricer) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request);
    }

    /** Add a listing, or raise the quantity of one already there. */
    public function add(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_product_id' => ['required', 'integer', 'exists:branch_products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $listing = BranchProduct::query()->findOrFail($validated['branch_product_id']);

        // Refuse at the door rather than at checkout. A shopper who fills a
        // basket from a shop that has since closed, or with a line that has
        // sold out, should learn now.
        if (! $listing->isBuyable()) {
            return new JsonResponse([
                'message' => 'That item is not available right now.',
                'code' => 'item_unavailable',
            ], 409);
        }

        $cart = $this->cart($request);
        $item = $cart->items()->firstOrNew(['branch_product_id' => $listing->id]);

        $wanted = (int) $item->qty + $validated['qty'];

        // Against the WANTED total, not against one. Stock was checked as
        // "is there any", so a basket could hold a thousand of a shelf that
        // held three — and the order's value, and therefore its refund, had
        // no ceiling at all.
        if (! $listing->canSupply($wanted)) {
            return new JsonResponse([
                'message' => $listing->stock_qty === null
                    ? 'That item is not available right now.'
                    : sprintf('Only %d left at this shop.', (int) $listing->stock_qty),
                'code' => 'insufficient_stock',
            ], 409);
        }

        $item->qty = $wanted;
        // Snapshot the price it went in at, so a later change can be SAID.
        $item->unit_price_laari = (int) $listing->price_laari;
        $item->save();

        return $this->respond($request);
    }

    /** Set an exact quantity. Zero removes the line, as the stepper does. */
    public function update(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $cart = $this->cart($request);
        $row = $cart->items()->whereKey($item)->firstOrFail();

        if ($validated['qty'] === 0) {
            $row->delete();

            return $this->respond($request);
        }

        // The stepper goes through here, so the ceiling has to be here too —
        // guarding only the ADD path would leave the same hole one tap away.
        $listing = $row->listing;

        if ($listing === null || ! $listing->canSupply($validated['qty'])) {
            return new JsonResponse([
                'message' => $listing?->stock_qty === null
                    ? 'That item is not available right now.'
                    : sprintf('Only %d left at this shop.', (int) $listing->stock_qty),
                'code' => 'insufficient_stock',
            ], 409);
        }

        $row->forceFill(['qty' => $validated['qty']])->save();

        return $this->respond($request);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->cart($request)->items()->whereKey($item)->delete();

        return $this->respond($request);
    }

    /** Empty it. One tap, because the alternative is many. */
    public function clear(Request $request): JsonResponse
    {
        $this->cart($request)->items()->delete();

        return $this->respond($request);
    }

    /**
     * The priced cart, against the address the shopper is buying for.
     *
     * `address_id` may be passed to price for an address other than the
     * default — the checkout's address step changes the delivery terms of
     * every subcart at once, and the screen must be able to show that before
     * committing to it.
     */
    private function respond(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $cart = $this->cart($request);

        $requested = $request->integer('address_id');

        $address = $requested > 0
            ? $customer->addresses()->whereKey($requested)->first()
            : $customer->addresses()->where('is_default', true)->first();

        return new JsonResponse(['data' => $this->pricer->price($cart, $address)]);
    }

    private function cart(Request $request): Cart
    {
        return Cart::query()->firstOrCreate([
            'customer_id' => $this->customer($request)->getKey(),
        ]);
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }
}
