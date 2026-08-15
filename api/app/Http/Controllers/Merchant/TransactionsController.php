<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Cashback\TransactionState;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Merchant transactions read model (§10): the filterable list behind the
 * panel's transactions table and the settlement builder's line picker.
 * Scoped through the authenticated merchant's own relation — another
 * merchant's transactions simply never appear.
 */
class TransactionsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'state' => ['sometimes', 'string', Rule::enum(TransactionState::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $query = $user->merchant->transactions()
            // The panel offers a correction on every row still inside its
            // window, and correcting a LINED sale means editing its lines —
            // so the split travels with the list rather than needing a
            // second request per row the moment someone opens the dialog.
            ->with(['lines' => fn ($lines) => $lines->orderBy('sort')])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if (isset($validated['state'])) {
            $query->where('state', $validated['state']);
        }

        return TransactionResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 25))->appends($request->query()),
        );
    }
}
