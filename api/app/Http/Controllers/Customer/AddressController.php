<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Zoning\ZoneAssigner;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where to bring it (PLAN-marketplace.md §2.5, `Delivery Details Step.png`).
 *
 * The island is RESOLVED from the pin, never taken from what the customer
 * typed: the island a courier drives to and the island a delivery rule is
 * priced against must be the same island, and free text cannot guarantee
 * that.
 */
final class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->customer($request)->addresses()
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map($this->present(...))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $validated = $this->rules($request);

        $address = DB::transaction(function () use ($customer, $validated): CustomerAddress {
            $address = $customer->addresses()->create([
                ...$validated,
                'zone_id' => ZoneAssigner::zoneIdFor($validated['lat'] ?? null, $validated['lng'] ?? null),
                // The first address a customer saves is their default; there
                // is nothing else it could be.
                'is_default' => $validated['is_default'] ?? ! $customer->addresses()->exists(),
            ]);

            $this->settleDefault($customer, $address);

            return $address;
        });

        return new JsonResponse(['data' => $this->present($address->refresh())], 201);
    }

    public function update(Request $request, int $address): JsonResponse
    {
        $customer = $this->customer($request);
        $row = $customer->addresses()->whereKey($address)->firstOrFail();
        $validated = $this->rules($request);

        DB::transaction(function () use ($customer, $row, $validated): void {
            $row->fill($validated);

            // Re-resolve whenever the pin moves. An address whose zone was
            // decided by an old pin would be priced for the wrong island.
            if (array_key_exists('lat', $validated)) {
                $row->zone_id = ZoneAssigner::zoneIdFor($row->lat, $row->lng);
            }

            $row->save();
            $this->settleDefault($customer, $row);
        });

        return new JsonResponse(['data' => $this->present($row->refresh())]);
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $customer = $this->customer($request);
        $row = $customer->addresses()->whereKey($address)->firstOrFail();
        $wasDefault = $row->is_default;

        DB::transaction(function () use ($customer, $row, $wasDefault): void {
            $row->delete();

            // Never leave a customer with addresses and no default — the
            // checkout would open with nothing selected.
            if ($wasDefault) {
                $customer->addresses()->orderBy('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'building' => ['required', 'string', 'max:160'],
            'island' => ['sometimes', 'nullable', 'string', 'max:80'],
            'area_magu' => ['sometimes', 'nullable', 'string', 'max:120'],
            'apartment_floor' => ['sometimes', 'nullable', 'string', 'max:80'],
            'delivery_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            // A pair, always — half a coordinate is a delivery in the sea.
            //
            // Neither `sometimes` nor `present`. With `sometimes` an absent
            // key is never validated, so `required_with` on the missing half
            // can never fire and a lone `lat` sails through to the database
            // constraint as a 500; with `present` an address without a pin —
            // perfectly legitimate — would be refused. Bare `nullable` lets
            // both be absent while still catching one without the other.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /** Exactly one default, always. */
    private function settleDefault(Customer $customer, CustomerAddress $address): void
    {
        if (! $address->is_default) {
            return;
        }

        $customer->addresses()
            ->whereKeyNot($address->getKey())
            ->update(['is_default' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomerAddress $address): array
    {
        return [
            'id' => $address->id,
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
            // Null zone is not an error: the Maldives is bigger than the
            // islands we have drawn. It means no branch can quote delivery
            // there yet, which the checkout says in words.
            'zone_name' => $address->zone?->name,
            'is_default' => $address->is_default,
        ];
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }
}
