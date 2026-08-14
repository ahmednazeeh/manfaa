<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settlement\InvalidSettlementStateException;
use App\Domain\Settlement\SettlementAllocator;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettlementResource;
use App\Models\AdminUser;
use App\Models\SettlementPayment;
use Illuminate\Http\Request;

/**
 * The matching step of the settlement queue: an admin confirms a claimed
 * bank payment arrived, and the allocator confirms whole transactions
 * oldest-first (§7) — forgiveness, overpayment and remainder handling all
 * live in the domain layer.
 */
class SettlementPaymentController extends Controller
{
    public function match(Request $request, int $id, SettlementAllocator $allocator): SettlementResource
    {
        $payment = SettlementPayment::query()->findOrFail($id);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $settlement = $allocator->matchPayment($payment, $admin);
        } catch (InvalidSettlementStateException $e) {
            abort(409, $e->getMessage());
        }

        return new SettlementResource($settlement->refresh()->load(['lines.transaction', 'payments']));
    }
}
