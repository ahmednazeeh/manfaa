<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Laari;
use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementState;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettlementPaymentResource;
use App\Http\Resources\SettlementResource;
use App\Models\Settlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The admin side of the settlement matching queue (§10): batch listing by
 * state, batch detail with lines, and recording claimed bank payments
 * against a batch. Matching itself lives on SettlementPaymentController.
 */
class SettlementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'state' => ['sometimes', Rule::enum(SettlementState::class)],
        ]);

        return SettlementResource::collection(
            Settlement::query()
                ->when(isset($validated['state']), fn ($query) => $query->where('state', $validated['state']))
                ->orderByDesc('id')
                ->paginate(25),
        );
    }

    public function show(int $id): SettlementResource
    {
        return new SettlementResource(
            Settlement::query()->findOrFail($id)->load(['lines.transaction', 'payments']),
        );
    }

    public function storePayment(Request $request, int $id, SettlementAllocator $allocator): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'bank_ref' => ['required', 'string', 'max:128'],
            'slip_path' => ['nullable', 'string', 'max:255'],
        ]);

        $settlement = Settlement::query()->findOrFail($id);

        try {
            $payment = $allocator->recordBankPayment(
                $settlement,
                Laari::of((int) $validated['amount']),
                $validated['bank_ref'],
                $validated['slip_path'] ?? null,
            );
        } catch (DuplicateBankRefException|InvalidSettlementStateException $e) {
            abort(409, $e->getMessage());
        }

        return (new SettlementPaymentResource($payment))
            ->response($request)
            ->setStatusCode(201);
    }
}
