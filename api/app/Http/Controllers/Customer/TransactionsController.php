<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerTransactionResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The customer's own earning history (§10 apps/web), newest first, with the
 * §6 customer-facing status mapping and reason KEYS (frontend translates).
 * Scoped through the authenticated customer's relation — anyone else's rows
 * simply never appear.
 */
class TransactionsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');

        return CustomerTransactionResource::collection(
            $customer->transactions()
                ->with('merchant:id,name,slug')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate((int) ($validated['per_page'] ?? 25))
                ->appends($request->query()),
        );
    }
}
