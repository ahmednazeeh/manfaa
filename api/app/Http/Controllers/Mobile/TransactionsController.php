<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Cashback\TransactionState;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerTransactionResource;
use App\Http\Resources\TransactionResource;
use App\Models\Customer;
use App\Models\MerchantUser;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * History for the apps, CURSOR-paged.
 *
 * WHY NOT THE PANELS' OFFSET PAGING: these lists are newest-first and grow at
 * the top. With `?page=2`, a sale credited between the user fetching page 1
 * and page 2 shifts every row down one — so the client re-renders a row it
 * already showed and never sees the one pushed across the boundary. On an
 * infinite scroll that reads as duplicated and missing history, and it is
 * worst exactly when the app is most useful: at a till, while sales are
 * landing.
 *
 * A cursor anchors on the last row actually seen, so insertions above it
 * cannot disturb the walk. It is also cheaper — a keyset seek rather than an
 * OFFSET the database has to count past.
 *
 * The panels keep page numbers on their own routes: a table with numbered
 * pages wants them, and that surface is free to differ (PLAN-mobile-api.md
 * §2.3).
 *
 * Rows are scoped through the authenticated holder's OWN relation, so
 * another customer's or merchant's history simply never appears — the
 * authorisation is the query.
 */
final class TransactionsController extends Controller
{
    private const int DEFAULT_PER_PAGE = 25;

    /**
     * A cursor this API never issued but that still base64-decodes reaches
     * Laravel's addCursorConditions, which throws UnexpectedValueException
     * on the missing parameter — unmapped, so it would answer 500 and log an
     * unhandled exception on an authenticated route. It is also what every
     * cursor already held in the field would become the day the ordering
     * columns change. Answer 422 so the client discards it and restarts the
     * walk from the top.
     */
    private function paginated(callable $paginate): CursorPaginator
    {
        try {
            return $paginate();
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages([
                'cursor' => 'cursor_invalid',
            ]);
        }
    }

    public function customer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');

        $page = $this->paginated(fn () => $customer->transactions()
            // Eager-loaded: the list names the store on every row, and
            // resolving that per row is the classic N+1 this endpoint would
            // otherwise commit 25 times per screen.
            ->with('merchant:id,name,slug')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE))
            ->withQueryString());

        return response()->json([
            'data' => CustomerTransactionResource::collection($page->items())
                ->resolve($request),
            'page' => self::cursorMeta($page),
        ]);
    }

    public function merchant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['sometimes', 'string', Rule::enum(TransactionState::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $query = $user->merchant->transactions()
            ->with(['lines' => fn ($lines) => $lines->orderBy('sort')])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if (isset($validated['state'])) {
            $query->where('state', $validated['state']);
        }

        $page = $this->paginated(fn () => $query
            ->cursorPaginate((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE))
            ->withQueryString());

        return response()->json([
            'data' => TransactionResource::collection($page->items())->resolve($request),
            'page' => self::cursorMeta($page),
        ]);
    }

    /**
     * The only two things a client needs to keep walking.
     *
     * No total count on purpose: counting the whole history to render one
     * screen is the expensive half of offset pagination, and nothing in
     * either app displays it.
     *
     * @param  CursorPaginator<int, mixed>  $page
     * @return array<string, mixed>
     */
    private static function cursorMeta($page): array
    {
        return [
            'next_cursor' => $page->nextCursor()?->encode(),
            'has_more' => $page->hasMorePages(),
        ];
    }
}
