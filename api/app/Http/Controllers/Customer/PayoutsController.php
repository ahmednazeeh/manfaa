<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Payout\PayoutItemState;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerPayoutDetailResource;
use App\Http\Resources\CustomerPayoutResource;
use App\Models\Customer;
use App\Models\PayoutItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The customer's own payout history: the money that actually reached their
 * bank account, and — one level down — exactly which purchases each payment
 * covered.
 *
 * Scoped through the authenticated customer's own relation, so another
 * customer's payout is indistinguishable from one that does not exist.
 *
 * PENDING items are deliberately EXCLUDED from the list. A pending item
 * belongs to a draft batch that has not been approved, let alone sent;
 * showing it would promise the customer money on a date nobody has
 * committed to, which is the one thing §9 says never to do. The customer's
 * unpaid balance already has a home — the dashboard's confirmed total — and
 * that is a balance, not a payment.
 */
class PayoutsController extends Controller
{
    /**
     * States a customer may see. `failed` is included on purpose: a
     * transfer the bank rejected is something that HAPPENED to their money,
     * and hiding it would leave them staring at a balance that silently
     * refuses to move.
     *
     * @var list<string>
     */
    private const array VISIBLE_STATES = ['sent', 'paid', 'failed'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');

        return CustomerPayoutResource::collection(
            $customer->payoutItems()
                ->whereIn('state', self::VISIBLE_STATES)
                ->with('batch:id,reference,period_start,period_end,state')
                ->withCount('transactions')
                ->orderByDesc('id')
                ->paginate((int) ($validated['per_page'] ?? 25))
                ->appends($request->query()),
        );
    }

    /**
     * One payout with the purchases it covered — the invoice, the store and
     * the cashback of each, so a customer can reconcile the deposit on
     * their bank statement against the shops they used.
     */
    public function show(Request $request, int $id): CustomerPayoutDetailResource
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $payout = PayoutItem::query()
            ->whereKey($id)
            ->where('customer_id', $customer->id)
            ->whereIn('state', self::VISIBLE_STATES)
            ->with([
                'batch:id,reference,period_start,period_end,state',
                'transactions' => fn ($query) => $query
                    ->with('merchant:id,name,name_dv,slug')
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id'),
            ])
            ->firstOrFail();

        return new CustomerPayoutDetailResource($payout);
    }

    /** @return list<string> the states a customer is shown */
    public static function visibleStates(): array
    {
        return array_map(
            static fn (string $state): string => PayoutItemState::from($state)->value,
            self::VISIBLE_STATES,
        );
    }
}
