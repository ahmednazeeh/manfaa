<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\PayoutsController as WebPayoutsController;
use App\Http\Resources\CustomerPayoutDetailResource;
use App\Http\Resources\CustomerPayoutResource;
use App\Models\Customer;
use App\Models\PayoutItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * The customer app's payout history (round R3) — the mobile projection of
 * the web PayoutsController, sharing its resources and its rules verbatim:
 * scoped through the customer's own rows, pending items EXCLUDED (a pending
 * item is a promise nobody has committed to — §9), failed items INCLUDED
 * (a bounced transfer HAPPENED to their money).
 *
 * Cursor-paged like every mobile list: payouts arrive monthly so the volume
 * is small, but the contract (guide §6) gives the apps ONE pagination shape
 * and this list keeps it.
 */
final class PayoutsController extends Controller
{
    private const int DEFAULT_PER_PAGE = 25;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');

        // A decodable-but-malformed cursor reaches Laravel's
        // addCursorConditions and throws UnexpectedValueException — the
        // same 500 TransactionsController already turns into a 422 so a
        // client holding a stale cursor self-heals instead of retrying a
        // permanent failure forever. Payouts must not be the one list that
        // 500s where the others recover.
        try {
            $page = $customer->payoutItems()
                ->whereIn('state', WebPayoutsController::visibleStates())
                ->with('batch:id,reference,period_start,period_end,state')
                ->withCount('transactions')
                ->orderByDesc('id')
                ->cursorPaginate((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE))
                ->withQueryString();
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['cursor' => 'cursor_invalid']);
        }

        return new JsonResponse([
            'data' => CustomerPayoutResource::collection($page->items())
                ->resolve($request),
            'page' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, int $id): CustomerPayoutDetailResource
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        // Identical scoping to the web: another customer's payout is
        // indistinguishable from one that does not exist.
        $payout = PayoutItem::query()
            ->whereKey($id)
            ->where('customer_id', $customer->id)
            ->whereIn('state', WebPayoutsController::visibleStates())
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
}
