<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\BranchDeliveryRule;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One branch's delivery matrix (PLAN-marketplace.md §2.4).
 *
 * A row per island the branch serves. Writing one adds or edits an island;
 * deleting one stops serving there. The islands on offer are the platform's
 * (`zones`), so opening a new island for every merchant is one zone row.
 *
 * INSTANT, not gated. A delivery fee is operational — a shop reacting to a
 * ferry strike should not wait a day — and unlike a store's name it is not a
 * claim a shopper has already relied on: the cart re-quotes at checkout.
 */
final class DeliveryRuleController extends Controller
{
    /** Every island the platform serves, and this branch's terms for each. */
    public function index(Request $request, int $branch): JsonResponse
    {
        $merchant = $this->merchant($request);
        $row = $merchant->branches()->whereKey($branch)->firstOrFail();

        $rules = $row->deliveryRules()->get()->keyBy('zone_id');

        return new JsonResponse([
            'data' => Zone::query()->orderBy('sort_order')->get(['id', 'name', 'name_dv'])
                ->map(function (Zone $zone) use ($rules): array {
                    $rule = $rules->get($zone->id);

                    return [
                        'zone_id' => $zone->id,
                        'zone_name' => $zone->name,
                        'zone_name_dv' => $zone->name_dv,
                        // The absence of a rule IS the answer: we do not
                        // deliver there.
                        'delivers' => $rule !== null,
                        'free_delivery_over_laari' => $rule?->free_delivery_over_laari,
                        'delivery_fee_laari' => $rule?->delivery_fee_laari,
                        'order_minimum_laari' => $rule?->order_minimum_laari,
                        'eta_min' => $rule?->eta_min,
                        'eta_max' => $rule?->eta_max,
                    ];
                })->values(),
        ]);
    }

    /** Add an island, or change its numbers. */
    public function upsert(Request $request, int $branch): JsonResponse
    {
        $merchant = $this->merchant($request);
        $row = $merchant->branches()->whereKey($branch)->firstOrFail();

        $validated = $request->validate([
            'zone_id' => ['required', 'integer', Rule::exists('zones', 'id')],
            'free_delivery_over_laari' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'delivery_fee_laari' => ['required', 'integer', 'min:0', 'max:10000000'],
            'order_minimum_laari' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'eta_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'eta_max' => ['nullable', 'integer', 'min:0', 'max:1440', 'gte:eta_min'],
        ]);

        $rule = BranchDeliveryRule::query()->updateOrCreate(
            ['branch_id' => $row->id, 'zone_id' => $validated['zone_id']],
            $validated,
        );

        return new JsonResponse(['data' => $rule], 200);
    }

    /** Stop serving an island. */
    public function destroy(Request $request, int $branch, int $zone): JsonResponse
    {
        $merchant = $this->merchant($request);
        $row = $merchant->branches()->whereKey($branch)->firstOrFail();

        $row->deliveryRules()->where('zone_id', $zone)->delete();

        return new JsonResponse(null, 204);
    }

    private function merchant(Request $request): Merchant
    {
        $user = $request->user('merchant');
        abort_unless($user instanceof MerchantUser, 403);

        $merchant = $user->merchant;
        abort_if($merchant === null, 403);

        return $merchant;
    }
}
