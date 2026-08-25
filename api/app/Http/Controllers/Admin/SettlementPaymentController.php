<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Laari;
use App\Domain\Settlement\DuplicateBankRefException;
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
    /**
     * The ceiling on a hand-stated bank figure: MVR 1,000,000 in laari.
     * Not a business rule — a typo guard. A reviewer who really is matching
     * a larger transfer splits it or asks an engineer, which is the right
     * amount of friction for a figure nothing downstream re-checks.
     */
    public const int MAX_RECEIVED_LAARI = 100_000_000;

    /**
     * `received_laari` is OPTIONAL here and means WHAT THE STATEMENT SAYS
     * ARRIVED (owner, 2026-08-25). The merchant's `amount_laari` is a claim;
     * the bank credit is the fact, and a reviewer holding the statement is
     * the only source of that fact on this path. Omitting it keeps the row's
     * existing figure — the verifier's stamp where one exists, and the claim
     * where nobody ever had a better number, which is what every hand match
     * spent before this field existed.
     *
     * Unlike the wallet top-up match, it is not required: an admin also
     * records payments here on behalf of a merchant (POST .../payments), and
     * refusing every match without a restated figure would block reconciling
     * work that is correct today. The panel prefills it with the claim and
     * shows the reviewer what matching will allocate.
     */
    public function match(Request $request, int $id, SettlementAllocator $allocator): SettlementResource
    {
        $validated = $request->validate([
            // Integer laari, as everywhere. Positive: a credit of nothing is
            // not a credit, and an outgoing figure is not this form's business.
            // Bounded above (verifier round, 2026-08-25): this figure goes
            // straight to the allocation and parks its surplus in the wallet
            // as spendable credit, so a slipped digit would mint money. MVR
            // 1,000,000 is far above any real settlement transfer and far
            // below anything a typo produces.
            'received_laari' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_RECEIVED_LAARI],
            // What the reviewer read off the SAME statement line. Optional —
            // an admin also reconciles payments whose merchant quoted no
            // reference at all — but without it a hand-matched credit is
            // named nowhere, and BankCreditClaim reports it unspent forever.
            'bank_ref' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $payment = SettlementPayment::query()->findOrFail($id);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $settlement = $allocator->matchPayment(
                $payment,
                $admin,
                isset($validated['received_laari'])
                    ? Laari::of((int) $validated['received_laari'])
                    : null,
                $validated['bank_ref'] ?? null,
            );
        } catch (InvalidSettlementStateException $e) {
            abort(409, $e->getMessage());
        } catch (DuplicateBankRefException $e) {
            abort(409, $e->getMessage());
        }

        return new SettlementResource($settlement->refresh()->load(['lines.transaction', 'payments']));
    }
}
