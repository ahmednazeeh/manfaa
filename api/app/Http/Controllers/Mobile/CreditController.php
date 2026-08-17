<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Cashback\CreditRecorder;
use App\Domain\Cashback\LineSetParser;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Money\Laari;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Http\Support\HandlesCreditRequests;
use App\Http\Support\OccurredAt;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The till app's credit.
 *
 * Shares its rules and its domain-exception mapping with the panel
 * (HandlesCreditRequests) so the two cannot drift on a money path. Two
 * things are different, and both exist because a phone is not a browser:
 *
 * 1. AN IDEMPOTENCY KEY IS REQUIRED (route middleware). A till loses signal
 *    mid-shift, queues its sales locally and drains them when it comes back;
 *    without a key, a request that timed out after the server committed
 *    would be replayed as a second sale. `(merchant_id, invoice_no)` is the
 *    final backstop, but it answers 409 — the key answers with the ORIGINAL
 *    response, which is what lets the queue clear cleanly.
 *
 * 2. A BACKDATED SALE MUST BE ACKNOWLEDGED. See below.
 */
final class CreditController extends Controller
{
    use HandlesCreditRequests;

    public function __construct(
        private readonly ManualCreditService $credits,
        private readonly LineSetParser $lineParser,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->creditRules() + [
            // Required only when the sale IS backdated — see below.
            'backdated_acknowledged' => ['sometimes', 'boolean'],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $occurredAt = OccurredAt::fromRequest($validated);

        /*
         * THE CLOCK GUARD.
         *
         * A sale older than the merchant's refund window skips
         * awaiting_validation entirely: it can never be reversed by the
         * store and is payable to Manfaa immediately. In the panel a human
         * ticks a box acknowledging that. A till app has no human in that
         * loop — and a tablet whose clock has drifted, or reset to epoch
         * after a flat battery, would stamp every queued sale far enough
         * back to trip the rule and silently create irreversible debt for a
         * whole shift.
         *
         * So the app must SAY it means it. An accidental clock is refused;
         * a genuine correction passes by sending the flag. The rule itself
         * is asked of the domain (CreditRecorder::wouldBeBackdated) rather
         * than recomputed here, because two copies of that arithmetic is
         * precisely how this guard would come to disagree with the
         * behaviour it is guarding.
         */
        if (
            CreditRecorder::wouldBeBackdated($user->merchant, $occurredAt, CarbonImmutable::now('UTC'))
            && ! ($validated['backdated_acknowledged'] ?? false)
        ) {
            return new JsonResponse([
                'message' => 'This sale is older than your refund window. Once credited it cannot be reversed and is payable immediately — resend with backdated_acknowledged to confirm, or check this device\'s clock.',
                'code' => 'backdated_confirmation_required',
                'occurred_at' => $occurredAt->toIso8601ZuluString(),
                'server_time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            ], 422);
        }

        $eligible = Laari::of((int) $validated['eligible_amount']);

        [$overrideRateBp, $refusal] = $this->overrideRateBp($validated, $user);

        if ($refusal !== null) {
            return $refusal;
        }

        [$lines, $refusal] = $this->creditLines($validated, $user, $eligible, $this->lineParser);

        if ($refusal !== null) {
            return $refusal;
        }

        [$transaction, $refusal] = $this->recordCredit(
            $this->credits,
            fn (ManualCreditService $credits) => $credits->credit(
                merchant: $user->merchant,
                actor: $user,
                customerCode: $validated['customer_code'],
                invoiceNo: $validated['invoice_no'],
                eligible: $eligible,
                saleAmount: isset($validated['sale_amount']) ? Laari::of((int) $validated['sale_amount']) : null,
                occurredAt: $occurredAt,
                lines: $lines,
                overrideRateBp: $overrideRateBp,
            ),
        );

        if ($refusal !== null) {
            return $refusal;
        }

        if ($lines !== null) {
            $transaction->load('lines');
        }

        return (new TransactionResource($transaction))
            ->response($request)
            ->setStatusCode(201);
    }
}
