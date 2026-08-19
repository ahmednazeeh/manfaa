<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MerchantBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Favourite shops (PLAN-marketplace.md §11.2, plan round MP11).
 *
 * Per BRANCH, not per merchant: the branch is the storefront everywhere else
 * in this domain — it holds the stock, the delivery terms and the hours —
 * and a shopper favourites the shop they buy from, not the company that owns
 * it.
 *
 * A toggle rather than separate add/remove verbs. The client has a heart
 * with two states and no third thing to say, and an idempotent PUT/DELETE
 * pair would still need the client to know which one to send.
 */
final class FavouriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return new JsonResponse([
            'data' => DB::table('customer_favourite_branches')
                ->where('customer_id', $customer->getKey())
                ->orderByDesc('id')
                ->pluck('branch_id')
                ->values(),
        ]);
    }

    public function toggle(Request $request, MerchantBranch $branch): JsonResponse
    {
        $customer = $this->customer($request);

        $existing = DB::table('customer_favourite_branches')
            ->where('customer_id', $customer->getKey())
            ->where('branch_id', $branch->getKey());

        if ($existing->exists()) {
            $existing->delete();

            return new JsonResponse(['data' => ['favourite' => false]]);
        }

        // insertOrIgnore, not insert: a double-tap is a double-tap, and the
        // unique index should decide the outcome rather than throw at it.
        DB::table('customer_favourite_branches')->insertOrIgnore([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branch->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return new JsonResponse(['data' => ['favourite' => true]]);
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }
}
