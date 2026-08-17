<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Cashback\LineSetParser;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Money\Laari;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Http\Support\HandlesCreditRequests;
use App\Http\Support\OccurredAt;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The panel's manual credit.
 *
 * The rules and the domain-exception mapping live in HandlesCreditRequests,
 * shared with the till app's endpoint (Mobile\CreditController) so the two
 * cannot drift apart on a money path. What differs is deliberate: the panel
 * has a human ticking a box for a backdated sale, and no idempotency key,
 * because a browser form is not an offline queue.
 */
class CreditController extends Controller
{
    use HandlesCreditRequests;

    public function __construct(
        private readonly ManualCreditService $credits,
        private readonly LineSetParser $lineParser,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->creditRules());

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

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
                occurredAt: OccurredAt::fromRequest($validated),
                lines: $lines,
                overrideRateBp: $overrideRateBp,
            ),
        );

        if ($refusal !== null) {
            return $refusal;
        }

        // Only lined credits expose their pricing split; single-rate
        // responses keep the exact pre-lines shape.
        if ($lines !== null) {
            $transaction->load('lines');
        }

        return (new TransactionResource($transaction))
            ->response($request)
            ->setStatusCode(201);
    }
}
