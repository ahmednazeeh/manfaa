<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Cashback\CustomerNotFoundException;
use App\Domain\Cashback\DuplicateInvoiceException;
use App\Domain\Cashback\FutureDatedTransactionException;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\MerchantNotActiveException;
use App\Domain\Cashback\NoEffectiveRateException;
use App\Domain\Money\Laari;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function __construct(private readonly ManualCreditService $credits) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_code' => ['required', 'string', 'digits:6'],
            'invoice_no' => ['required', 'string', 'max:64'],
            'eligible_amount' => ['required', 'integer', 'min:0'],
            'sale_amount' => ['nullable', 'integer', 'gte:eligible_amount'],
            // ISO 8601 with an explicit UTC offset (§9.2). An offset-less
            // wall-clock string would be read as UTC and freeze the rate at
            // the wrong instant, so it is rejected outright.
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:sp,Y-m-d\TH:i:sO'],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        try {
            $transaction = $this->credits->credit(
                merchant: $user->merchant,
                actor: $user,
                customerCode: $validated['customer_code'],
                invoiceNo: $validated['invoice_no'],
                eligible: Laari::of((int) $validated['eligible_amount']),
                saleAmount: isset($validated['sale_amount']) ? Laari::of((int) $validated['sale_amount']) : null,
                occurredAt: CarbonImmutable::parse($validated['occurred_at']),
            );
        } catch (CustomerNotFoundException $e) {
            abort(404, $e->getMessage());
        } catch (DuplicateInvoiceException $e) {
            abort(409, $e->getMessage());
        } catch (MerchantNotActiveException|FutureDatedTransactionException|NoEffectiveRateException $e) {
            abort(422, $e->getMessage());
        }

        return (new TransactionResource($transaction))
            ->response($request)
            ->setStatusCode(201);
    }
}
